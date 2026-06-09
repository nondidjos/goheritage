<?php

/**
 * plan-viewer plugin
 *
 * Drop-in plan / large-image viewer with anti-download tile streaming.
 *
 *   1. A `plan` file template (site/blueprints/files/plan.yml) classifies
 *      uploads as plans — drawings, relevés, scanned blueprints.
 *
 *   2. On upload (file.create:after + file.replace:after) we spawn
 *      generate-tiles.js, which uses Sharp's DZI writer to produce a
 *      DeepZoom pyramid alongside the source file:
 *
 *          plan-rdc.jpg
 *          plan-rdc.dzi          ← XML manifest (~1 kB)
 *          plan-rdc_files/       ← tile pyramid (one folder per zoom level)
 *
 *   3. The public template renders a `plan-viewer` snippet that wires the
 *      thumbnail to OpenSeadragon, served from the .dzi URL. The original
 *      full-res file URL is never sent to the browser.
 *
 *   4. On file deletion (file.delete:before) we clean up the orphan
 *      tile directory + manifest.
 *
 * Page methods exposed:
 *   $page->plans()           – Kirby Files collection, template='plan'
 *   $page->planTiles($file)  – returns the .dzi URL or null if not tiled yet
 */

use Kirby\Cms\App as Kirby;
use Kirby\Cms\File;
use Kirby\Filesystem\Dir;
use Kirby\Filesystem\F;
use Kirby\Http\Response;

Kirby::plugin('goheritage/plan-viewer', [

    // ── Routes ────────────────────────────────────────────────────────
    //
    // /plan-tiles/<page-id>/<rel-path> serves the .dzi manifest and tile
    // pyramid files (which live under <basename>_files/N/x_y.jpeg) that
    // Apache's content-folder block would otherwise hide.
    //
    // Hard limits:
    //   • <page-id> must resolve to a real page.
    //   • Page must pass canBeViewedWithToken(?key=…) for non-admins.
    //   • Path must end in .dzi OR be inside a *_files/ directory.
    //   • Extension must be in a small image whitelist.
    //   • Realpath must stay inside the page's own content directory
    //     (so a `..` in the path can't escape into other content).
    //
    'routes' => [
        [
            'pattern' => 'plan-tiles/(:any)/(:all)',
            'action'  => function (string $pageId, string $relPath) {
                $kirby = kirby();
                // URLs encode Kirby's `/` page-id separator as `+` so the
                // whole ID lives in a single (:any) segment, leaving the
                // tile path (which has its own `/`s) in (:all).
                $resolvedId = str_replace('+', '/', $pageId);
                $page       = $kirby->page($resolvedId);
                if (!$page) {
                    return new Response('', 'text/plain', 404);
                }

                // Access check — admins always pass, others use the same
                // visibility logic the project template uses for sections.
                $user    = $kirby->user();
                $isAdmin = $user && $user->isAdmin();
                if (!$isAdmin) {
                    $token = get('key');
                    if (method_exists($page, 'canBeViewedWithToken')) {
                        if (!$page->canBeViewedWithToken($token)) {
                            return new Response('', 'text/plain', 404);
                        }
                    } elseif (!$page->isListed()) {
                        // No plugin method (project-ux not loaded) → fall back
                        // to status: listed pages are public, drafts hidden.
                        return new Response('', 'text/plain', 404);
                    }
                }

                // Whitelist allowed file shapes:
                //   • plan-rdc.dzi             (DZI manifest)
                //   • plan-rdc_files/N/x_y.jpeg (tile pyramid)
                $isDzi   = preg_match('/^[\w.\-]+\.dzi$/', $relPath) === 1;
                $isTile  = preg_match('/^[\w.\-]+_files\/\d+\/\d+_\d+\.(jpe?g|png|webp)$/', $relPath) === 1;
                if (!$isDzi && !$isTile) {
                    return new Response('', 'text/plain', 404);
                }

                // Resolve & validate path stays inside the page's content dir
                $pageRoot = realpath($page->root());
                $target   = realpath($pageRoot . '/' . $relPath);
                if (!$target || !str_starts_with($target, $pageRoot . DIRECTORY_SEPARATOR)) {
                    return new Response('', 'text/plain', 404);
                }
                if (!is_file($target)) {
                    return new Response('', 'text/plain', 404);
                }

                // Mime + cache headers
                $ext   = strtolower(pathinfo($target, PATHINFO_EXTENSION));
                $mime  = match ($ext) {
                    'dzi'         => 'application/xml',
                    'jpg', 'jpeg' => 'image/jpeg',
                    'png'         => 'image/png',
                    'webp'        => 'image/webp',
                    default       => 'application/octet-stream',
                };

                return new Response(
                    file_get_contents($target),
                    $mime,
                    200,
                    [
                        // Tiles never change once generated → long cache.
                        'Cache-Control' => 'public, max-age=31536000, immutable',
                        // Prevent hot-linking via referer mismatch could go here
                        // if you want to lock the viewer to your domain only.
                    ]
                );
            },
        ],
    ],

    // ── Spawn tile generation as a background job ──────────────────────
    // Returns immediately so the upload UI doesn't block; tiles appear
    // a few seconds later when generate-tiles.js finishes.
    'hooks' => [

        'file.create:after' => function (File $file) {
            goheritage_plan_viewer_generate($file);
        },

        'file.replace:after' => function (File $newFile) {
            goheritage_plan_viewer_generate($newFile);
        },

        // Clean up orphan tile directory when the source is deleted.
        'file.delete:before' => function (File $file) {
            $base    = pathinfo($file->root(), PATHINFO_DIRNAME);
            $stem    = pathinfo($file->filename(), PATHINFO_FILENAME);
            $tiles   = $base . '/' . $stem . '_files';
            $dzi     = $base . '/' . $stem . '.dzi';
            if (is_dir($tiles))  { Dir::remove($tiles); }
            if (is_file($dzi))   { F::remove($dzi); }
        },
    ],

    // ── Page methods for templates ─────────────────────────────────────
    'pageMethods' => [

        // Files explicitly tagged as plans (template: plan).
        // Falls back to any PDF / DWG / DXF / SVG when no template is set.
        'plans' => function () {
            $explicit = $this->files()->filterBy('template', 'plan');
            if ($explicit->count() > 0) {
                return $explicit;
            }
            // Fallback heuristic: extension-based, excludes obvious non-plans
            return $this->files()->filter(function ($f) {
                $ext = strtolower($f->extension());
                if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'tif', 'tiff', 'pdf', 'svg', 'dwg', 'dxf'], true)) {
                    return false;
                }
                // Skip 3D textures / normals / previews / template=image gallery shots
                $name = strtolower($f->filename());
                if (str_contains($name, 'texture'))  return false;
                if (str_contains($name, 'normal'))   return false;
                if (str_contains($name, 'diffuse'))  return false;
                if (str_contains($name, 'preview'))  return false;
                if ($f->template() === 'image')      return false;
                if ($f->template() === 'cover')      return false;
                // Heuristic — only treat as plan if filename hints at it
                return preg_match('/(plan|releve|relev[ée]|drawing|blueprint|elevation|coupe|fa[cç]ade|niveau|rdc|sous-?sol|etage|étage)/i', $name) === 1;
            });
        },

        // Returns the .dzi manifest URL for a given plan file, or null if
        // tiles haven't been generated yet. PDFs are tiled via pdftoppm
        // inside generate-tiles.js, so they get the same URL shape.
        // The URL routes through /plan-tiles/<page-id>/<filename>.dzi so
        // the access check + path whitelist run on every request, including
        // for the tile pyramid that OpenSeadragon discovers via the .dzi.
        'planTiles' => function (?File $file = null) {
            if ($file === null) return null;
            $stem = pathinfo($file->filename(), PATHINFO_FILENAME);
            if (!is_file($this->root() . '/' . $stem . '.dzi')) {
                return null;
            }
            $encodedId = str_replace('/', '+', $this->id());
            return url('plan-tiles/' . $encodedId . '/' . $stem . '.dzi');
        },

        /**
         * Returns a thumbnail URL for a tiled plan, or null if no tiles.
         *
         * Picks the smallest DZI zoom level whose 0_0 tile is at LEAST
         * `targetPx` pixels on its larger side, so the result is sharp
         * for thumbnail display without dragging the full-res image.
         *
         * DZI level N has the image scaled to max(width, height) = 2^N.
         * For a 240 px thumbnail target, level 8 (max 256 px) is ideal —
         * the whole image fits in the single 0_0 tile.
         *
         * Clamped to the manifest's maximum level so tiny source images
         * don't ask for a level that doesn't exist.
         */
        'planThumbUrl' => function (?File $file = null, int $targetPx = 240) {
            if ($file === null) return null;
            $stem    = pathinfo($file->filename(), PATHINFO_FILENAME);
            $dziPath = $this->root() . '/' . $stem . '.dzi';
            if (!is_file($dziPath)) return null;

            // Parse the manifest (cheap — ~250 bytes) to learn the image
            // dimensions so we can compute the correct zoom level.
            $xml = @simplexml_load_file($dziPath);
            if ($xml === false) return null;
            $w = (int) ($xml->Size['Width']  ?? 0);
            $h = (int) ($xml->Size['Height'] ?? 0);
            if ($w <= 0 || $h <= 0) return null;

            $maxDim   = max($w, $h);
            $maxLevel = (int) ceil(log($maxDim, 2));
            $wanted   = (int) max(0, ceil(log(max(1, $targetPx), 2)));
            $level    = min($wanted, $maxLevel);

            $encodedId = str_replace('/', '+', $this->id());
            return url('plan-tiles/' . $encodedId . '/' . $stem . '_files/' . $level . '/0_0.jpeg');
        },
    ],

]);

/**
 * Spawn the tile generator for a single file. Returns immediately —
 * tiles materialise a few seconds later, picked up on next page view.
 *
 * Only runs for raster images Sharp can read; PDFs / DWG / DXF are
 * skipped with a logged note (handled by the client-side fallback).
 */
function goheritage_plan_viewer_generate(File $file): void {
    $ext = strtolower($file->extension());

    // Raster + PDF supported. DWG/DXF still needs a dedicated converter.
    // PDFs go through pdftoppm inside generate-tiles.js for rasterisation.
    if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'tif', 'tiff', 'pdf'], true)) {
        return;
    }

    // Heuristic gate: only generate tiles for files that look like plans,
    // so uploading a regular gallery photo doesn't trigger tile generation.
    $name = strtolower($file->filename());
    $isExplicitPlan = $file->template() === 'plan';
    $looksLikePlan  = (bool) preg_match('/(plan|releve|relev[ée]|drawing|blueprint|elevation|coupe|fa[cç]ade|niveau|rdc|sous-?sol|etage|étage)/i', $name);
    if (!$isExplicitPlan && !$looksLikePlan) {
        return;
    }

    $script = __DIR__ . '/generate-tiles.js';
    $src    = $file->root();
    $dst    = pathinfo($src, PATHINFO_DIRNAME) . '/' . pathinfo($src, PATHINFO_FILENAME);

    // Spawn in the background so the Panel upload request returns immediately;
    // tiles materialise a few seconds later. The shared node-job runner
    // (goheritage-core) handles binary resolution, the '&' detach on POSIX,
    // and the Windows sync fallback. Errors land in /tmp/ since the plugin
    // directory is owned by `bitnami` (not the web-server user).
    goheritageNodeJob($script, [$src, $dst], [
        'background' => true,
        'logFile'    => sys_get_temp_dir() . '/goheritage-tile.log',
    ]);
}
