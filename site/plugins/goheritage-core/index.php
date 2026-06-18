<?php

/**
 * goheritage-core — shared infrastructure for the GoHéritage plugins.
 *
 * Hosts the Node shell-out module: one deep interface that every plugin's
 * "shell out to a Node CLI" path goes through, replacing four divergent
 * exec() sites (model-converter ×3, hotspot-detector ×1, plan-viewer ×1)
 * that each re-implemented binary resolution, argument escaping, stderr
 * capture, and the sync-vs-background split.
 *
 * Also hosts the canonical annotation row builder so the hotspot pipeline
 * and its readers share one shape instead of three implicit copies.
 *
 * Kirby loads plugin folders alphabetically (goheritage-core < hotspot-
 * detector < model-converter < plan-viewer), and every caller is a route or
 * hook action that runs at request time — well after all plugin index.php
 * files have registered — so these globals are always defined when called.
 * Each is guarded with function_exists() so a stale copy elsewhere can't
 * fatal on redeclare.
 */

use Kirby\Cms\App as Kirby;

Kirby::plugin('goheritage/core', [
    // ── Strip HTML comments from production output ──────────────────────────
    // PHP comments never reach the browser, but the `<!-- … -->` markup
    // comments in templates/snippets DO show up in "view source". This hook
    // removes them from the final HTML in production only (dev keeps them so
    // the rendered source stays debuggable). Runs before Kirby caches the
    // page, so the cached copy is already clean.
    'hooks' => [
        'page.render:after' => function (string $contentType, array $data, string $html, $page) {
            if ($contentType !== 'html' || ghIsLocalEnv()) {
                return $html;
            }
            return ghStripHtmlComments($html);
        },
    ],

    // ── Thumbnails via Sharp/libvips, not GD ────────────────────────────────
    // GD decodes the WHOLE source into memory to resize it (a 14 MB JPEG ≈
    // 100 MB of pixels, a 100 MB PNG far more) — which OOMs/stalls thumb
    // generation on the 450 MB box, so big gallery images "struggle to show
    // up". Sharp streams the source and peaks at tens of MB regardless of size.
    // This override routes every ->thumb()/->crop()/->resize() through Sharp;
    // on any failure (or an effect Sharp isn't wired for here, e.g. blur) it
    // falls back to Kirby's stock GD darkroom so an image can never break.
    'components' => [
        'thumb' => function ($kirby, string $src, string $dst, array $options): string {
            try {
                if (goheritageSharpThumb($src, $dst, $options)) {
                    return $dst;
                }
            } catch (\Throwable $e) {
                // fall through to GD
            }
            $darkroom = \Kirby\Image\Darkroom::factory(
                $kirby->option('thumbs.driver', 'gd'),
                $kirby->option('thumbs', [])
            );
            $options = $darkroom->preprocess($src, $options);
            \Kirby\Filesystem\F::copy($src, $dst, true);
            $darkroom->process($dst, $options);
            return $dst;
        },
    ],
]);

if (!function_exists('goheritageSharpThumb')) {
    /**
     * Generate one thumbnail with Sharp (see thumb.js). Returns true on a
     * verified write, false to let the GD darkroom fall back. Only the resize
     * options the site actually uses are handled here (width/height/crop/
     * quality/format); anything else (blur, grayscale, sharpen) returns false
     * so Kirby's stock pipeline takes over.
     */
    function goheritageSharpThumb(string $src, string $dst, array $options): bool
    {
        if (!empty($options['blur']) || !empty($options['grayscale']) || !empty($options['sharpen'])) {
            return false;
        }
        $script = __DIR__ . '/thumb.js';
        if (!is_file($script) || !is_file($src)) {
            return false;
        }
        $w       = isset($options['width'])  ? (int) $options['width']  : 0;
        $h       = isset($options['height']) ? (int) $options['height'] : 0;
        $crop    = !empty($options['crop']);
        $quality = (int) ($options['quality'] ?? kirby()->option('thumbs.quality', 80));
        // The dst extension is what Kirby will serve, so encode to match it.
        $format  = strtolower(pathinfo($dst, PATHINFO_EXTENSION)) ?: 'jpg';

        $dir = dirname($dst);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        $res = goheritageNodeJob($script, [$src, $dst, $w, $h, $crop ? 1 : 0, $quality, $format], [
            'timeout'    => 60,
            'logChannel' => 'thumbs',
        ]);
        return !empty($res['ok']) && is_file($dst) && filesize($dst) > 0;
    }
}

if (!function_exists('ghStripHtmlComments')) {
    /**
     * Remove HTML comments from a rendered document, then squeeze the blank
     * lines they leave behind. Deliberately conservative:
     *
     *   - IE conditional comments (`<!--[if …]>`) are preserved — the negative
     *     lookahead skips any comment opening with `[if`.
     *   - Only runs of whitespace-only lines are collapsed (never two tags on
     *     one line), so significant whitespace between inline elements is
     *     untouched.
     *
     * Does NOT touch JS/CSS comments inside <script>/<style> — those would
     * need a real minifier to strip safely; the front-end JS is already
     * minified at build time (esbuild, comments dropped).
     */
    function ghStripHtmlComments(string $html): string
    {
        $out = preg_replace('/<!--(?!\[if).*?-->/s', '', $html);
        if ($out === null) {
            return $html; // regex failure (e.g. backtrack limit) — ship original
        }
        // Collapse runs of now-empty lines down to a single newline.
        $out = preg_replace('/(?:[ \t]*\R){2,}/', "\n", $out);
        return $out ?? $html;
    }
}

if (!function_exists('ghIsLocalEnv')) {
    /**
     * Local dev host? (localhost / loopback / *.test / *.local / LAN).
     * Production serves the minified, comment-stripped assets; dev serves
     * the readable sources so the browser debugger shows real code.
     */
    function ghIsLocalEnv(): bool
    {
        $host = kirby()->environment()->host() ?? '';
        return $host === 'localhost'
            || str_starts_with($host, '127.')
            || str_starts_with($host, '192.168.')
            || str_ends_with($host, '.test')
            || str_ends_with($host, '.local');
    }
}

if (!function_exists('ghAsset')) {
    /**
     * Resolve a front-end asset URL with two production conveniences:
     *
     *   1. In production a `.js` path is swapped for its `.min.js` sibling
     *      when that built file exists (deploy runs `npm run build`). Dev
     *      keeps the readable source.
     *   2. A cache-bust (?v=<filemtime>) is appended automatically, so
     *      changing a file invalidates the browser cache with no manual
     *      version bumping.
     *
     * Falls back to the source path (and no version) if the file can't be
     * stat'd, so a missing build never 500s a page.
     *
     * @param string $path  e.g. 'assets/js/viewer.js' or 'assets/css/app.css'
     */
    function ghAsset(string $path): string
    {
        $path    = ltrim($path, '/');
        $docroot = kirby()->root('index');

        if (!ghIsLocalEnv() && str_ends_with($path, '.js')) {
            $min = substr($path, 0, -3) . '.min.js';
            if (is_file($docroot . '/' . $min)) {
                $path = $min;
            }
        }

        $abs = $docroot . '/' . $path;
        $url = url($path);
        if (is_file($abs)) {
            $url .= '?v=' . filemtime($abs);
        }
        return $url;
    }
}

if (!function_exists('goheritageSocialLinks')) {
    /**
     * Footer social links that are safe to render: a non-empty platform name
     * AND a real http(s) URL. One definition shared by the public footer
     * snippet and the panel's gh/footer-data sync so they can't diverge — a
     * javascript:/data: URL or a half-filled row never reaches either.
     */
    function goheritageSocialLinks($site = null)
    {
        $site = $site ?? site();
        return $site->social()->toStructure()->filter(function ($s) {
            $url = trim((string) $s->url());
            return $url !== ''
                && preg_match('~^https?://~i', $url)
                && trim((string) $s->platform()) !== '';
        });
    }
}

if (!function_exists('goheritageNodeBin')) {
    /**
     * Resolve the node binary. Checks known platform paths before falling
     * back to a bare "node" (works when /usr/bin is in PHP-FPM's PATH, which
     * it is on the Bitnami/NodeSource stack).
     */
    function goheritageNodeBin(): string {
        static $resolved = null;
        if ($resolved !== null) return $resolved;

        $candidates = [
            '/usr/bin/node',          // Linux — NodeSource / system package
            '/usr/local/bin/node',
            '/usr/bin/nodejs',
            '/opt/homebrew/bin/node', // macOS arm64
            'C:\\Program Files\\nodejs\\node.exe',
            'C:\\Program Files (x86)\\nodejs\\node.exe',
        ];
        foreach ($candidates as $c) {
            if (file_exists($c) && is_executable($c)) {
                return $resolved = $c;
            }
        }
        $which = PHP_OS_FAMILY === 'Windows' ? 'where node 2>nul' : 'which node 2>/dev/null';
        $out   = @shell_exec($which);
        if ($out) {
            $path = trim(explode("\n", $out)[0]);
            if ($path && file_exists($path)) return $resolved = $path;
        }
        return $resolved = 'node';
    }
}

if (!function_exists('goheritageNodeLog')) {
    /**
     * Append a timestamped line to site/logs/<channel>.log. Silently no-ops
     * if the directory isn't writable.
     */
    function goheritageNodeLog(string $channel, string $msg): void {
        $logDir  = __DIR__ . '/../../logs';
        $logFile = $logDir . '/' . $channel . '.log';
        if (!is_dir($logDir)) @mkdir($logDir, 0775, true);
        @file_put_contents($logFile, '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n", FILE_APPEND | LOCK_EX);
    }
}

if (!function_exists('goheritageNodeJob')) {
    /**
     * Run a Node CLI script. One interface for every shell-out.
     *
     *   $script   absolute path to the .js entry point
     *   $args     ordered CLI arguments (flags + paths); each is shell-escaped
     *   $opts     [
     *               'maxOldSpace' => ?int     --max-old-space-size value, or
     *                                         null to omit the flag (default null)
     *               'background'  => bool     fire-and-forget; appends "&" on
     *                                         POSIX. Windows always runs sync
     *                                         (default false)
     *               'timeout'     => ?int     hard wall-clock limit in seconds,
     *                                         enforced via coreutils timeout(1)
     *                                         on POSIX (exit 124 on expiry).
     *                                         Bounds every job by construction
     *                                         so a stalled child can't outlive
     *                                         the PHP request as an orphan.
     *                                         Ignored on Windows (default null)
     *               'logChannel'  => ?string  log the command + result to
     *                                         site/logs/<channel>.log
     *               'logFile'     => ?string  stdout/stderr append target for
     *                                         background jobs (defaults to a
     *                                         temp file); on the sync path the
     *                                         captured output is appended there
     *                                         too so the same file is useful on
     *                                         both paths
     *             ]
     *
     * Returns ['ok' => bool, 'code' => int, 'output' => string[]].
     * Background jobs return ok=true / code=0 / output=[] immediately — they
     * can't be awaited.
     */
    function goheritageNodeJob(string $script, array $args = [], array $opts = []): array {
        $node        = goheritageNodeBin();
        $maxOldSpace = $opts['maxOldSpace'] ?? null;
        $background  = $opts['background'] ?? false;
        $timeout     = $opts['timeout'] ?? null;
        $logChannel  = $opts['logChannel'] ?? null;
        $isWindows   = DIRECTORY_SEPARATOR === '\\';

        $parts = [];
        if ($timeout !== null && !$isWindows) {
            $parts[] = 'timeout ' . (int) $timeout;
        }
        $parts[] = escapeshellarg($node);
        if ($maxOldSpace !== null) {
            $parts[] = '--max-old-space-size=' . (int) $maxOldSpace;
        }
        $parts[] = escapeshellarg($script);
        foreach ($args as $arg) {
            $parts[] = escapeshellarg((string) $arg);
        }
        $cmd = implode(' ', $parts);

        // Background only makes sense on POSIX; Windows falls back to sync.
        if ($background && !$isWindows) {
            $logFile = $opts['logFile'] ?? (sys_get_temp_dir() . '/goheritage-node.log');
            $cmd .= ' >> ' . escapeshellarg($logFile) . ' 2>&1 &';
            if ($logChannel) goheritageNodeLog($logChannel, "spawn(bg): $cmd");
            @exec($cmd);
            return ['ok' => true, 'code' => 0, 'output' => []];
        }

        $cmd .= ' 2>&1';
        if ($logChannel) goheritageNodeLog($logChannel, "exec: $cmd");
        $output = []; $code = 0;
        exec($cmd, $output, $code);
        $timedOut = ($timeout !== null && !$isWindows && $code === 124);
        if ($logChannel) {
            goheritageNodeLog($logChannel, "  exit=$code" . ($timedOut ? " (timeout after {$timeout}s)" : '') . '  ' . implode(' | ', $output));
        }

        // Honour logFile on the sync path too (incl. the Windows fallback for
        // background jobs) so callers tailing that file see sync output as well.
        if (!empty($opts['logFile']) && $output) {
            @file_put_contents($opts['logFile'], implode("\n", $output) . "\n", FILE_APPEND | LOCK_EX);
        }

        return ['ok' => $code === 0, 'code' => $code, 'output' => $output];
    }
}

if (!function_exists('goheritageAnnotationRow')) {
    /**
     * Canonical annotation row shape — the single definition of what an
     * annotation is on disk. The hotspot pipeline writes these; readers
     * (allAnnotations page method, the project template) consume them.
     * Centralising the shape here is what stops a missing key from silently
     * producing a null-valued row that breaks the 3D viewer.
     *
     *   $values  any subset of the canonical keys
     * Returns a row with every key present and sane defaults applied.
     *
     * location and camera_mode use ?: (not ??) deliberately: Kirby's
     * Field::value() returns '' for a present-but-empty field, and an empty
     * enum value is just as broken for the viewer as a missing one. Callers
     * therefore don't need their own ->or() defaults for these keys.
     */
    function goheritageAnnotationRow(array $values): array {
        return [
            'location'    => ($values['location']    ?? null) ?: 'exterior',
            'hotspot_id'  => ($values['hotspot_id']  ?? null) ?: (($values['id'] ?? null) ?: ''),
            'title'       => $values['title']       ?? '',
            'camera_mode' => ($values['camera_mode'] ?? null) ?: 'fly',
            'description' => $values['description'] ?? '',
        ];
    }
}
