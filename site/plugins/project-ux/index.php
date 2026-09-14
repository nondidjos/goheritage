<?php

/**
 * project-ux plugin
 *
 * Panel UX layer for project pages:
 *   - visibility view-button     : public / private switch in the page header
 *
 * The earlier `view-mode-toggle` and `visibility-control` fields have been
 * retired:
 *
 *   • view-mode-toggle was a global page-wide CSS disabler that ignored
 *     Kirby's native save/discard flow. Replaced by per-section pencil
 *     buttons (see Per-section edit pattern).
 *
 *   • visibility-control was a row of three card-buttons that sat as a
 *     section in the blueprint. Replaced by `k-visibility-view-button`
 *     (registered in index.js) which lives in the page header next to the
 *     status and preview buttons, like Matterport's sharing control.
 *
 * Page methods exposed for the public template:
 *   isPubliclyVisible()        – page visibility is `public`
 *   sectionVisible($key)       – is the named section in `visible_sections`?
 *   visibilityResolved()       – effective visibility: `public` or `private`
 *                                (the retired `link` tier maps to private).
 */

use Kirby\Cms\App as Kirby;

// READ-access gate for a project page's content, shared by the gated asset
// route (gh/file) and the ZIP download (gh/download) so the authorization
// lives in exactly one place and can't drift between them. Any panel user is
// allowed; an anonymous visitor only ever reaches a publicly visible page,
// and never the bulk archive.
if (!function_exists('gh_requester_may_access')) {
    function gh_requester_may_access($page, bool $requireDownload = false): bool
    {
        if (kirby()->user()) {
            return true;
        }
        return $requireDownload ? false : $page->isPubliclyVisible();
    }
}

Kirby::plugin('goheritage/project-ux', [

    // ── Custom frontend sharing routes ───────────────────────────────────
    'routes' => [

        // VISIBILITY-GATED ASSET DELIVERY.
        // 3D models and point clouds are served ONLY through this route, never
        // as static /media files (the .htaccess hard-blocks those extensions
        // under /media). Apache can't see a page's visibility, so a published
        // /media copy of a private/link project's model was downloadable by
        // anyone who guessed the URL — this re-checks the SAME access the page
        // itself enforces (panel session, or a valid share token) before
        // streaming the original from the (non-web-served) content dir, with
        // HTTP Range support so the COPC viewer keeps working.
        [
            'pattern' => 'gh/file/(:any)/(:any)',
            'method'  => 'GET|HEAD',
            'action'  => function (string $encodedId, string $rawName) {
                $kirby = kirby();
                $page  = $kirby->page(str_replace('+', '/', $encodedId));
                if (!$page || $page->intendedTemplate()->name() !== 'project') {
                    $kirby->response()->code(404);
                    return 'Not found';
                }

                // Same authorisation as viewing the page itself.
                if (!gh_requester_may_access($page)) {
                    $kirby->response()->code(403);
                    return 'Accès refusé.';
                }

                $file = $page->file(rawurldecode($rawName));
                if (!$file) {
                    $kirby->response()->code(404);
                    return 'Not found';
                }
                goheritageStreamFile($file->root(), $file->mime() ?: 'application/octet-stream');
                // goheritageStreamFile() streams + exit()s; never reached.
                return '';
            },
        ],

        // STRUCTURED ZIP DOWNLOAD. Packages all project files into a ZIP with
        // category subfolders and project-slug-prefixed filenames so the
        // recipient gets a self-describing archive instead of Kirby's flat
        // file directory. Requires a logged-in panel user.
        [
            'pattern' => 'gh/download/(:any)',
            'method'  => 'GET',
            'action'  => function (string $encodedId) {
                $kirby  = kirby();
                $pageId = str_replace('+', '/', $encodedId);
                $page   = $kirby->page($pageId);

                if (!$page || $page->intendedTemplate()->name() !== 'project') {
                    $kirby->response()->code(404);
                    return $kirby->site()->errorPage()->render();
                }

                // Auth: panel user (scoped roles limited to their project) OR a
                // viewer/editor share token for THIS page. requireDownload=true
                // denies visit-only tokens (they have no file access).
                if (!gh_requester_may_access($page, true)) {
                    $kirby->response()->code(403);
                    return 'Accès refusé.';
                }

                if (!class_exists('ZipArchive')) {
                    header('HTTP/1.1 500 Internal Server Error');
                    echo 'ZipArchive non disponible sur ce serveur.';
                    exit;
                }

                // Map the shared fileCategory() key → archive subfolder. The
                // classification logic itself lives on the File object (see the
                // fileMethods block) so the download and the Fichiers browser
                // stay in lockstep.
                $keyFolder = [
                    'model-source'   => 'modele-3d/source',
                    'model-web'      => 'modele-3d/web',
                    'texture-source' => 'modele-3d/textures/source',
                    'texture-web'    => 'modele-3d/textures/web',
                    'hotspot'        => 'modele-3d/hotspots',
                    'cloud'          => 'nuage-de-points',
                    'photo'          => 'photos',
                    'doc'            => 'documents',
                    'data'           => 'donnees',
                    'video'          => 'videos',
                    'archive'        => 'archives',
                    'other'          => 'autres',
                ];

                // Ordered folder → human description for the README legend.
                // Only folders that actually receive a file are listed.
                $folderInfo = [
                    'modele-3d/source'          => "Fichiers 3D bruts tels qu'importés (OBJ, MTL, FBX…).",
                    'modele-3d/web'             => 'Modèle optimisé pour le web (GLB compressé Draco).',
                    'modele-3d/textures/source' => "Textures haute résolution d'origine (PNG, TIFF…).",
                    'modele-3d/textures/web'    => 'Textures compressées (WebP) et aperçus.',
                    'modele-3d/hotspots'        => "Données des points d'intérêt (JSON).",
                    'nuage-de-points'           => 'Nuages de points bruts (LAS, LAZ, E57, PLY…).',
                    'photos'                    => 'Photographies de présentation.',
                    'documents'                 => 'Documents (PDF, Word…).',
                    'donnees'                   => 'Données structurées (CSV, XML…).',
                    'videos'                    => 'Vidéos.',
                    'archives'                  => 'Archives compressées.',
                    'autres'                    => 'Fichiers non classés.',
                ];

                $slug = $page->slug();
                $tmp  = tempnam(sys_get_temp_dir(), 'gh_pkg_');
                $zip  = new \ZipArchive();

                if ($zip->open($tmp, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
                    header('HTTP/1.1 500 Internal Server Error');
                    echo 'Impossible de créer l\'archive.';
                    exit;
                }

                // Give PHP enough time for large archives (point clouds etc.).
                @set_time_limit(300);

                $usedNames   = [];  // collision guard for identical stems
                $usedFolders = [];  // subfolders that actually received a file
                                    // → README lists only these

                foreach ($page->files() as $file) {
                    $absPath = $file->root();
                    if (!is_readable($absPath)) continue;

                    $ext    = strtolower($file->extension());
                    $folder = $keyFolder[$file->fileCategory()] ?? 'autres';
                    $usedFolders[$folder] = true;

                    // Use the file's title if the editor filled it in and it
                    // differs from the raw filename, otherwise fall back to the
                    // filename stem. Slugify either way so the path stays clean.
                    $titleField = trim($file->title()->value());
                    $rawStem    = pathinfo($file->filename(), PATHINFO_FILENAME);
                    $stem       = \Kirby\Toolkit\Str::slug(
                        ($titleField && $titleField !== $file->filename()) ? $titleField : $rawStem
                    );

                    // Build the in-archive path; deduplicate with a counter if needed.
                    $base    = $slug . '_' . $stem . '.' . $ext;
                    $zipPath = $slug . '/' . $folder . '/' . $base;
                    if (isset($usedNames[$zipPath])) {
                        $usedNames[$zipPath]++;
                        $zipPath = $slug . '/' . $folder . '/' . $slug . '_' . $stem . '-' . $usedNames[$zipPath] . '.' . $ext;
                    } else {
                        $usedNames[$zipPath] = 1;
                    }

                    $zip->addFile($absPath, $zipPath);
                }

                // README built last so it can describe exactly the folders that
                // ended up with files — a legend for the archive's layout, not
                // a random metadata dump.
                $title  = (string) $page->title();
                $readme = [
                    $title,
                    str_repeat('=', max(3, mb_strlen($title))),
                    '',
                    'Dossier exporté depuis GoHéritage le ' . date('d/m/Y à H:i') . '.',
                    '',
                ];

                $infos = [];
                if ($page->location()->isNotEmpty())  $infos[] = 'Localisation        : ' . $page->location();
                if ($page->date()->isNotEmpty())      $infos[] = 'Date de numérisation : ' . $page->date();
                if ($page->architect()->isNotEmpty()) $infos[] = 'Architecte          : ' . $page->architect();
                if ($infos) {
                    $readme[] = 'INFORMATIONS';
                    $readme[] = '------------';
                    $readme   = array_merge($readme, $infos);
                    $readme[] = '';
                }

                $readme[] = 'STRUCTURE DU DOSSIER';
                $readme[] = '--------------------';
                foreach ($folderInfo as $folder => $desc) {
                    if (!empty($usedFolders[$folder])) {
                        $readme[] = str_pad($folder . '/', 30) . $desc;
                    }
                }
                $readme[] = '';
                $readme[] = "Les fichiers sont préfixés par l'identifiant du projet (« " . $slug . "_ »).";

                $zip->addFromString($slug . '/README.txt', implode("\n", $readme) . "\n");

                $zip->close();

                $downloadName = $slug . '_dossier.zip';
                $size         = filesize($tmp);

                // Tell the panel's download button that compression is finished
                // and the byte stream is about to start — it polls for this
                // cookie to drop its "Compression…" spinner. Keyed to the token
                // the browser passed in ?dl= so a stale download can't clear the
                // spinner of a newer one. Not HttpOnly: the JS must read it.
                if ($dlToken = get('dl')) {
                    setcookie('gh_dl_done', preg_replace('/[^A-Za-z0-9]/', '', (string) $dlToken), [
                        'expires'  => time() + 300,
                        'path'     => '/',
                        'samesite' => 'Lax',
                        'secure'   => !ghIsLocalEnv(),
                        'httponly' => false,
                    ]);
                }

                header('Content-Type: application/zip');
                header('Content-Disposition: attachment; filename="' . $downloadName . '"');
                header('Content-Length: ' . $size);
                header('Cache-Control: no-cache, no-store, must-revalidate');
                header('Pragma: no-cache');
                header('Expires: 0');

                readfile($tmp);
                @unlink($tmp);
                exit;
            }
        ],
    ],

    // ── Custom API routes ──────────────────────────────────────────────────
    //
    //  PATCH  api/gh/pages/(:any)/visibility
    //  Body:  { "visibility": "private"|"link"|"public" }
    //
    //  Sets both the Kirby page status and the `visibility` content field
    //  in one atomic server-side call.  This bypasses the panel's built-in
    //  /status endpoint which rejects null positions for pages with num:0
    //  (auto-sorted by date) — resulting in "The status for this page cannot
    //  be changed".
    'api' => [
        'routes' => [
            [
                'pattern' => 'gh/pages/(:any)/visibility',
                'method'  => 'PATCH',
                'action'  => function (string $encodedId) {
                    $kirby  = kirby();

                    // Decode the panel-style ID (+ → /)
                    $pageId = str_replace('+', '/', $encodedId);
                    $page   = $kirby->page($pageId);

                    if (!$page) {
                        return ['status' => 'error', 'message' => 'Page not found: ' . $pageId];
                    }

                    // AUTHORIZATION — must be checked BEFORE impersonating kirby.
                    // The impersonation below bypasses Kirby's permission system
                    // entirely, so without this gate any logged-in user (incl. a
                    // could change any page's visibility. Require that the real
                    // current user actually holds update rights on THIS page.
                    $actor = $kirby->user();
                    if (!$actor || $page->permissions()->cannot('update')) {
                        $kirby->response()->code(403);
                        return ['status' => 'error', 'message' => 'Accès refusé.'];
                    }
                    $kirby->impersonate('kirby');

                    $body       = $kirby->request()->body();
                    $visibility = $body->get('visibility');

                    if (!in_array($visibility, ['private', 'public'], true)) {
                        return ['status' => 'error', 'message' => 'Invalid visibility value'];
                    }

                    // Map visibility onto Kirby's page status
                    $kirbyStatus = ($visibility === 'private') ? 'draft' : 'listed';

                    try {
                        // 1. Update the visibility content field
                        $page = $page->update(['visibility' => $visibility]);

                        // 2. Change Kirby status only when needed; for listed
                        //    pages with num:0 we pass position 0 to avoid the
                        //    "cannot be changed" error from a null position.
                        if ($page->status() !== $kirbyStatus) {
                            $position = ($kirbyStatus === 'listed') ? 0 : null;
                            $page = $page->changeStatus($kirbyStatus, $position);
                        }

                        return [
                            'status' => 'ok',
                            'id'     => $page->id(),
                            'panelId' => str_replace('/', '+', $page->id()),
                        ];
                    } catch (\Throwable $e) {
                        return ['status' => 'error', 'message' => $e->getMessage()];
                    }
                },
            ],

            // Read-only content preview for the Détails "showcase" mode.
            // Returns the cover, the rendered editorial blocks (same output
            // as the public page) and gallery thumbnails so the panel can
            // show a real preview instead of a CMS form.
            [
                'pattern' => 'gh/pages/(:any)/details-preview',
                'method'  => 'GET',
                'action'  => function (string $encodedId) {
                    $kirby  = kirby();
                    $pageId = str_replace('+', '/', $encodedId);
                    $page   = $kirby->page($pageId);

                    if (!$page) {
                        return ['status' => 'error', 'message' => 'Page not found'];
                    }

                    // Cover — same crop ratio as the public poster.
                    $cover    = $page->cover()->toFile();
                    $coverUrl = $cover ? $cover->crop(1600, 700)->url() : null;

                    // Editorial blocks rendered exactly as the public page
                    // renders them (unstyled by site CSS in the panel, but
                    // structurally identical: headings, text, images, etc.).
                    $blocksHtml = '';
                    try {
                        if ($page->text()->isNotEmpty()) {
                            $blocksHtml = (string) $page->text()->toBlocks()->toHtml();
                        }
                    } catch (\Throwable $e) {
                        $blocksHtml = '';
                    }

                    // Gallery thumbnails — shared galleryPhotos() so model
                    // assets (textures/normals) never show in the preview.
                    $gallery = $page->galleryPhotos();
                    $thumbs = [];
                    foreach ($gallery as $img) {
                        try { $thumbs[] = $img->crop(400, 300)->url(); }
                        catch (\Throwable $e) {}
                    }

                    return [
                        'status'     => 'ok',
                        'coverUrl'   => $coverUrl,
                        'blocksHtml' => $blocksHtml,
                        'gallery'    => $thumbs,
                    ];
                },
            ],

            // Everything the panel footer needs in one authenticated request:
            // site tagline, contact email, social links, and the top-level
            // public navigation pages. Requires a logged-in panel session (the
            // API route handler is called with the current user's auth context).
            [
                'pattern' => 'gh/footer-data',
                'method'  => 'GET',
                'action'  => function () {
                    $kirby = kirby();
                    // Impersonate kirby so the route can read site data
                    // regardless of the current panel user's page permissions.
                    $kirby->impersonate('kirby');
                    $site = $kirby->site();

                    $nav = [];
                    try {
                        // Mirror the public footer: exclude pages with the 'blog'
                        // template (the internal Kirby blog page) so the external
                        // "Blog GOVR ↗" link is the only blog entry shown.
                        foreach ($site->children()->listed()->filter(
                            fn($p) => $p->intendedTemplate()->name() !== 'blog'
                        ) as $p) {
                            $nav[] = [
                                'title' => (string) $p->title(),
                                'url'   => $p->url(),
                            ];
                        }
                    } catch (\Throwable $e) {}
                    // Mirror the public footer's external GOVR blog link.
                    $nav[] = ['title' => 'Blog GOVR ↗', 'url' => 'https://www.govr.eu/blog'];

                    $social = [];
                    try {
                        // Shared filter (non-empty platform + real http(s) url)
                        // so the panel footer matches the public one exactly.
                        foreach (goheritageSocialLinks($site) as $s) {
                            $social[] = [
                                'platform' => (string) $s->platform(),
                                'url'      => trim((string) $s->url()),
                            ];
                        }
                    } catch (\Throwable $e) {}

                    return [
                        'status'  => 'ok',
                        'tagline' => (string) $site->footer_tagline(),
                        'email'   => (string) $site->footer_email(),
                        'nav'     => $nav,
                        'social'  => $social,
                    ];
                },
            ],
        ],
    ],



    // ── Custom panel sections ─────────────────────────────────────────────
    //
    //  project-overview replaces the entire Aperçu tab with a single custom
    //  Vue-rendered card: cover image, meta chips, description, tags, and a
    //  list of asset tiles (3D, plans, gallery, hotspots, documents) that
    //  link to the relevant tabs. Each editable group opens a k-form-dialog
    //  on click — no more inline form chrome on the Aperçu tab.
    'sections' => [
        'project-overview' => [
            'computed' => [
                'pageId'           => function () { return $this->model()->id(); },
                'pageTitle'        => function () { return (string) $this->model()->title(); },

                // ── Cover image ─────────────────────────────────────────
                'coverUrl' => function () {
                    $cover = $this->model()->cover()->toFile();
                    if (!$cover) return null;
                    try {
                        return $cover->crop(1600, 600)->url();
                    } catch (\Throwable $e) {
                        return $cover->url();
                    }
                },
                'pageImages' => function () {
                    // Cover picker — only real photos, never model assets
                    // (textures/normals), so the texture maps can't be chosen
                    // as a cover or clutter the picker.
                    $images = [];
                    foreach ($this->model()->images() as $file) {
                        if ($file->isModelAsset()) continue;
                        $images[] = [
                            'name'  => $file->filename(),
                            'url'   => $file->url(),
                            'thumb' => $file->crop(200, 150)->url(),
                            'uuid'  => $file->uuid()->toString()
                        ];
                    }
                    return $images;
                },
                'coverUuid' => function () {
                    $cover = $this->model()->cover()->toFile();
                    return $cover ? $cover->uuid()->toString() : null;
                },
                'dateRaw' => function () {
                    return (string)$this->model()->date();
                },
                'protectionStatusRaw' => function () {
                    return (string)$this->model()->protection_status();
                },
                // ── Description / meta fields ───────────────────────────
                'description'      => function () { return (string) $this->model()->description(); },
                'location'         => function () { return (string) $this->model()->location(); },
                'constructionDate' => function () { return (string) $this->model()->construction_date(); },
                'scanDate'         => function () {
                    $d = (string) $this->model()->date();
                    if (!$d) return '';
                    try {
                        $dt = new \DateTime($d);
                        return $dt->format('d/m/Y');
                    } catch (\Throwable $e) {
                        return $d;
                    }
                },
                'architect'        => function () { return (string) $this->model()->architect(); },
                'style'            => function () { return (string) $this->model()->style(); },
                'dimensions'       => function () { return (string) $this->model()->dimensions(); },
                'protectionStatus' => function () {
                    $val = (string) $this->model()->protection_status();
                    $labels = [
                        'classé'   => 'Classé Monument Historique',
                        'unesco'   => 'Patrimoine mondial UNESCO',
                        'regional' => 'Inventaire Régional',
                        'none'     => 'Non protégé',
                    ];
                    return $labels[$val] ?? $val;
                },

                'lat' => function () { return (string) $this->model()->lat(); },
                'lng' => function () { return (string) $this->model()->lng(); },

                'tags' => function () {
                    return array_values(array_filter(array_map('trim',
                        $this->model()->tags()->split(',')
                    )));
                },
                'primaryTag' => function () { return (string) $this->model()->primary_tag(); },

                // ── Asset counts (for the asset tiles) ──────────────────
                'has3dModel' => function () {
                    $p = $this->model();
                    return $p->file('exterior.obj') || $p->file('interior.obj')
                        || $p->file('exterior.glb') || $p->file('interior.glb');
                },
                'modelSidesSummary' => function () {
                    $p = $this->model();
                    $ext = $p->file('exterior.obj') || $p->file('exterior.glb');
                    $int = $p->file('interior.obj') || $p->file('interior.glb');
                    if ($ext && $int) return 'Extérieur + intérieur';
                    if ($ext) return 'Extérieur';
                    if ($int) return 'Intérieur';
                    return 'Aucun modèle';
                },

                'galleryCount' => function () {
                    // Reflect what the public gallery actually shows (curated
                    // field or filtered fallback), so the overview tile count
                    // matches reality instead of only the explicit field.
                    return $this->model()->galleryPhotos()->count();
                },
                'plansCount' => function () {
                    if (method_exists($this->model(), 'plans')) {
                        return $this->model()->plans()->count();
                    }
                    return 0;
                },
                'docsCount' => function () {
                    return $this->model()->files()->filterBy('template', 'default')->count();
                },
                'hotspotsCount' => function () {
                    if (method_exists($this->model(), 'allAnnotations')) {
                        return $this->model()->allAnnotations()->count();
                    }
                    return 0;
                },
                'contentBlocksCount' => function () {
                    try {
                        return $this->model()->text()->toBlocks()->count();
                    } catch (\Throwable $e) {
                        return 0;
                    }
                },
            ],
        ],
    ],

    // ── Hooks ──────────────────────────────────────────────────────────────
    'hooks' => [

        // Default new projects to private so a project is never publicly
        // exposed before someone explicitly publishes it.
        'page.create:after' => function ($page) {
            if ($page->intendedTemplate()->name() === 'project') {
                try {
                    $page->update(['visibility' => 'private']);
                } catch (\Throwable $e) {
                    // Silent — the field is backfilled on next save.
                }
            }
        },

        // Ensure visibility and status fields are synchronized when changed
        // via Kirby's native Panel settings or API endpoints.
        'page.changeStatus:after' => function ($newPage, $oldPage) {
            if ($newPage->intendedTemplate()->name() === 'project') {
                try {
                    if ($newPage->isDraft()) {
                        $newPage->update(['visibility' => 'private']);
                    }
                } catch (\Throwable $e) {
                }
            }
        },

    ],

    // ── File methods ───────────────────────────────────────────────────────
    'fileMethods' => [
        // True when a file is an asset *for* the 3D model (geometry, material,
        // texture/PBR map, point cloud, hotspots) rather than a presentation
        // photo. Used to keep these out of the gallery, cover picker and every
        // other public surface — they should only ever show in the Fichiers
        // explorer. Centralised here so the rule lives in exactly one place.
        'isModelAsset' => function () {
            $ext = strtolower($this->extension());

            // 3D geometry, material and point-cloud formats.
            $modelExt = [
                'obj', 'glb', 'gltf', 'mtl', 'fbx', 'stl', 'dae', '3ds', 'drc',
                'ply', 'las', 'laz', 'e57', 'pcd', 'xyz', 'pts',
            ];
            if (in_array($ext, $modelExt, true)) {
                return true;
            }

            // Hotspot annotation data travels as JSON alongside the model.
            if ($ext === 'json') {
                return true;
            }

            // Image files that are PBR/material maps for the model — matched by
            // the naming tokens our converter (and typical DCC exports) use.
            $name = strtolower($this->filename());
            $tokens = [
                'texture', 'diffuse', 'albedo', 'basecolor', 'base-color', 'base_color',
                'normal', 'roughness', 'metallic', 'metalness', 'specular',
                'glossiness', 'displacement', 'emissive', 'emission', 'occlusion',
            ];
            foreach ($tokens as $t) {
                if (str_contains($name, $t)) {
                    return true;
                }
            }
            return false;
        },

        // Single classification key for a file, shared by the ZIP download
        // (key → folder) and the Fichiers browser (key → section). Keeping it
        // here means the two can never drift apart. Distinguishes raw geometry
        // from web-optimised, texture maps from real photos, and raw textures
        // from their compressed counterparts.
        'fileCategory' => function () {
            $ext  = strtolower($this->extension());
            $name = strtolower($this->filename());

            $rawGeo   = ['obj', 'mtl', 'fbx', 'stl', 'dae', '3ds'];
            $webGeo   = ['glb', 'gltf', 'drc'];
            $points   = ['ply', 'las', 'laz', 'e57', 'pcd', 'xyz', 'pts'];
            $images   = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'avif',
                         'tif', 'tiff', 'bmp', 'svg', 'tga', 'exr'];
            $docs     = ['pdf', 'doc', 'docx', 'odt', 'rtf', 'txt', 'md'];
            $data     = ['csv', 'xml', 'yml', 'yaml'];
            $videos   = ['mp4', 'mov', 'webm', 'avi', 'mkv'];
            $archives = ['rar', '7z', 'tar', 'gz', 'zip'];

            if (in_array($ext, $rawGeo, true)) return 'model-source';
            if (in_array($ext, $webGeo, true)) return 'model-web';
            if (in_array($ext, $points, true)) return 'cloud';

            if (in_array($ext, $images, true)) {
                if (!$this->isModelAsset()) return 'photo';
                $isWeb = $ext === 'webp'
                      || (in_array($ext, ['jpg', 'jpeg'], true) && str_contains($name, '-preview'));
                return $isWeb ? 'texture-web' : 'texture-source';
            }

            if ($ext === 'json') return $this->isModelAsset() ? 'hotspot' : 'data';
            if (in_array($ext, $docs, true))     return 'doc';
            if (in_array($ext, $data, true))     return 'data';
            if (in_array($ext, $videos, true))   return 'video';
            if (in_array($ext, $archives, true)) return 'archive';
            return 'other';
        },

        // True for a Cloud-Optimized Point Cloud. Detected by the `.copc.laz`
        // filename suffix because extension() is just "laz" — the canonical
        // place for that rule so the template, snippet and viewer-selector
        // can't drift on it.
        'isCopc' => function () {
            return (bool) preg_match('/\.copc\.laz$/i', $this->filename());
        },
    ],

    // ── Page methods for templates and controllers ─────────────────────────
    'pageMethods' => [

        // The page's COPC point cloud (newest), or null. Single source of the
        // detection rule for the ?pointcloud=1 stage, the visitor switcher
        // pane, and the header's viewer-script selector.
        'copcFile' => function () {
            return $this->files()->filter(fn ($f) => $f->isCopc())
                                  ->sortBy('modified', 'desc')
                                  ->first();
        },

        // URL for a project file. Model + point-cloud files are routed through
        // the visibility-gated gh/file route (static /media is hard-blocked for
        // those extensions — see .htaccess); everything else (images, JSON…)
        // keeps its fast static /media URL. The protected-extension list MUST
        // stay in sync with the .htaccess RewriteRule. The current share key is
        // propagated so a link/private visitor's authorised request carries its
        // token. Returns null for a missing file so callers keep their `? :`.
        'assetUrl' => function ($file) {
            if (!$file) {
                return null;
            }
            static $protected = [
                'glb', 'gltf', 'obj', 'mtl', 'fbx', 'stl', 'dae', '3ds', 'drc',
                'ply', 'pcd', 'las', 'laz', 'e57', 'xyz', 'pts',
                // Texture/normal-map images used by 3D models — gated so private
                // project textures aren't served raw from /media.
                'jpg', 'jpeg', 'png', 'webp',
            ];
            if (!in_array(strtolower($file->extension()), $protected, true)) {
                return $file->url();
            }
            return '/gh/file/' . str_replace('/', '+', $this->id())
                 . '/' . rawurlencode($file->filename());
        },

        // Whether a home-page section is shown. Sections default to visible, so
        // an unset toggle reads true; only an explicit off hides. Centralises
        // the default-true coercion the template otherwise repeated per section.
        'showSection' => function (string $key) {
            return $this->content()->get('show' . ucfirst($key))->toBool(true);
        },

        // Curated photos for the public gallery. Uses the explicit `gallery`
        // field when the editor has set it; otherwise falls back to the page's
        // images MINUS any 3D-model assets (textures, normals, previews, etc.)
        // and the cover, so raw model material never leaks into the gallery.
        // Single source of truth — template, dossier and the panel overview
        // all call this instead of re-implementing the filter.
        'galleryPhotos' => function () {
            $gallery = $this->gallery()->toFiles();
            if ($gallery->count() > 0) {
                return $gallery;
            }
            $coverId = ($cover = $this->cover()->toFile()) ? $cover->id() : null;
            return $this->images()
                ->filterBy('extension', 'in', ['jpg', 'jpeg', 'png', 'webp', 'gif', 'avif'])
                ->filter(fn ($f) => !$f->isModelAsset())
                ->filter(fn ($f) => $f->id() !== $coverId)
                ->sortBy('sort', 'asc');
        },

        // Photos that may be PICKED into the gallery field (blueprint query).
        // Any real photo regardless of template, minus 3D-model assets — the
        // old `page.images.template('image')` query actually matched textures
        // (template "image") and missed real photos (template "blocks/image").
        'galleryPickable' => function () {
            return $this->images()
                ->filterBy('extension', 'in', ['jpg', 'jpeg', 'png', 'webp', 'gif', 'avif'])
                ->filter(fn ($f) => !$f->isModelAsset());
        },

        // Effective visibility with backward-compat fallback for pages that
        // pre-date this plugin: listed → public, otherwise → private.
        // Effective visibility. The share-link tier was removed, so a project
        // is either public or private; the legacy `link` value maps to private
        // so existing content keeps exactly the non-public state it had.
        'visibilityResolved' => function () {
            return $this->visibility()->value() === 'public' ? 'public' : 'private';
        },

        'isPubliclyVisible' => function () {
            return $this->visibilityResolved() === 'public';
        },

        // Per-section visibility for the public project page, driven by the
        // page-level `visible_sections` list. An empty list means "show all".
        'sectionVisible' => function (string $section) {
            if ($this->visibilityResolved() !== 'public') {
                return false;
            }
            $field = $this->visible_sections();
            if ($field->isEmpty()) {
                return true;
            }
            return in_array($section, $field->split(','), true);
        },
    ],
]);
