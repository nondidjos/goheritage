<?php

/**
 * project-ux plugin
 *
 * Panel UX layer for project pages:
 *   - share-link field           : read-only URL with copy button (legacy
 *                                   inline render; the visibility view-button
 *                                   in the page header surfaces the same URL
 *                                   for the common case)
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
 *   isPubliclyVisible()        – page is `public` and `listed`
 *   isLinkOnly()               – page uses the `link` visibility tier
 *   canBeViewedWithToken($t)   – does the supplied ?key=... grant access?
 *   sectionVisible($key)       – is the named section in `visible_sections`?
 *   visibilityResolved()       – effective visibility with backward-compat:
 *                                if `visibility` is empty, fall back to status
 *                                (listed → public, draft → private) so pages
 *                                created before this plugin was installed keep
 *                                their existing behaviour until edited.
 */

use Kirby\Cms\App as Kirby;

// Write-guard for scoped `collaborator` accounts (created by editor share
// links). A collaborator may only mutate the single project they were
// granted — and its descendant pages/files. Any attempt to write outside
// that subtree throws. This is the server-side backstop behind the scoped
// panel menu; it is what actually makes the editor share safe to hand out.
if (!function_exists('gh_guard_collaborator_scope')) {
    function gh_guard_collaborator_scope($model): void
    {
        $user = kirby()->user();
        if (!$user) return;

        $role = $user->role()->name();

        // Viewers are fully read-only — block every write unconditionally.
        if ($role === 'viewer') {
            throw new \Kirby\Exception\PermissionException('Accès lecture seule.');
        }

        if ($role !== 'collaborator') return;

        $scoped = $user->scoped_page()->value();
        if (empty($scoped)) {
            throw new \Kirby\Exception\PermissionException('Compte de partage non lié à un projet.');
        }

        // Validate that the collaborator's share token is still active and has editor access
        $shareToken = $user->share_token()->value();
        $scopedPage = kirby()->page($scoped);
        if (!$scopedPage || empty($shareToken) || $scopedPage->shareTokenAccess($shareToken) !== 'editor') {
            throw new \Kirby\Exception\PermissionException('Ce lien d\'accès a été révoqué.');
        }

        // Resolve the page the write targets (files carry a parent page).
        $page = $model;
        if ($model instanceof \Kirby\Cms\File) {
            $page = $model->parent();
        }
        if (!($page instanceof \Kirby\Cms\Page)) {
            throw new \Kirby\Exception\PermissionException('Action non autorisée pour ce compte de partage.');
        }
        $id = $page->id();
        if ($id !== $scoped && strpos($id, $scoped . '/') !== 0) {
            throw new \Kirby\Exception\PermissionException('Accès limité à votre projet partagé.');
        }
    }
}

Kirby::plugin('goheritage/project-ux', [

    // ── Custom frontend sharing routes ───────────────────────────────────
    'routes' => [

        // SHARE LOGIN — handles both editor and viewer tokens.
        //
        // Editor  → named account signup (the existing collaborator flow):
        //           the recipient creates a password-protected account and
        //           gets full edit access scoped to the one project.
        //
        // Viewer  → auto-created, auto-login, no signup form:
        //           a minimal `viewer` account is created silently (random
        //           internal email, random password the user never sees).
        //           Each subsequent visit auto-logs them in via the token.
        //           Revoking the share link immediately kills their access.
        [
            'pattern' => 'gh-share-login/(:any)',
            'action'  => function (string $slug) {
                $kirby = kirby();
                $token = get('key');

                if (!$token) {
                    return $kirby->response()->redirect('/panel/login');
                }

                $map  = $kirby->page('map');
                $page = $map ? $map->children()->find($slug) : null;
                if (!$page) {
                    $page = $kirby->page($slug);
                }
                if (!$page || $page->intendedTemplate()->name() !== 'project') {
                    return $kirby->response()->redirect('/panel/login');
                }

                $access   = $page->shareTokenAccess($token);
                $panelId  = str_replace('/', '+', $page->id());
                $redirect = '/panel/pages/' . $panelId . '?tab=overview';

                // Only editor and viewer tokens are allowed here.
                if ($access !== 'editor' && $access !== 'viewer') {
                    return $kirby->response()->redirect('/panel/login');
                }

                // ── Viewer — auto-create once, then auto-login every time ──
                if ($access === 'viewer') {
                    // Handle already-logged-in users before creating a viewer session.
                    if ($existing = $kirby->user()) {
                        // Already the right viewer — nothing to do.
                        if ($existing->role()->name() === 'viewer'
                            && $existing->scoped_page()->value() === $page->id()
                            && $existing->share_token()->value() === $token) {
                            return $kirby->response()->redirect($redirect);
                        }
                        // Admin/author already has full panel access — send them
                        // straight to the project without touching their session.
                        if ($existing->isAdmin() || $existing->role()->name() === 'author') {
                            return $kirby->response()->redirect($redirect);
                        }
                        // Another scoped user (wrong viewer / collaborator) —
                        // log them out so the correct viewer session can start.
                        $existing->logout();
                    }

                    // The token has already been verified above — that IS the
                    // authentication. We don't need a password. loginPasswordless()
                    // with explicit cookie options creates a panel session directly,
                    // exactly as Kirby's own Auth::login() does internally.
                    $viewerEmail = 'viewer-' . substr(hash('sha256', $token), 0, 12) . '@gh.internal';

                    $viewer = $kirby->users()->filterBy('role', 'viewer')
                        ->filterBy('share_token', $token)->first();

                    if (!$viewer) {
                        $kirby->impersonate('kirby');
                        $viewer = $kirby->users()->create([
                            'name'     => 'Lecteur',
                            'email'    => $viewerEmail,
                            'password' => bin2hex(random_bytes(24)), // random, never used
                            'role'     => 'viewer',
                            'language' => 'fr',
                        ]);
                        $viewer->update([
                            'scoped_page' => $page->id(),
                            'share_token' => $token,
                        ]);
                        $kirby->impersonate();
                    }

                    // Create a cookie-based panel session directly on the user.
                    // No password check — token validity above is sufficient.
                    $viewer->loginPasswordless(['createMode' => 'cookie', 'long' => false]);

                    return $kirby->response()->redirect($redirect);
                }

                // ── Editor — existing named-account signup flow ──────────
                if ($user = $kirby->user()) {
                    if ($user->isAdmin() || $user->role()->name() === 'author'
                        || ($user->role()->name() === 'collaborator'
                            && $user->scoped_page()->value() === $page->id()
                            && $user->share_token()->value() === $token)) {
                        return $kirby->response()->redirect($redirect);
                    }
                }

                $existing = $kirby->users()->filterBy('role', 'collaborator')
                    ->filterBy('share_token', $token)->first();

                if ($existing) {
                    return snippet('gh-editor-signup', [
                        'page'      => $page,
                        'token'     => $token,
                        'slug'      => $slug,
                        'errors'    => [],
                        'form_data' => [],
                        'status'    => 'used',
                    ], true);
                }

                return snippet('gh-editor-signup', [
                    'page'      => $page,
                    'token'     => $token,
                    'slug'      => $slug,
                    'errors'    => [],
                    'form_data' => [],
                    'status'    => 'active',
                ], true);
            }
        ],

        [
            'pattern' => 'gh-share-register',
            'method'  => 'POST',
            'action'  => function () {
                $kirby = kirby();
                $token = get('token');
                $slug  = get('slug');

                if (!$token || !$slug) {
                    return $kirby->response()->redirect('/panel/login');
                }

                $map  = $kirby->page('map');
                $page = $map ? $map->children()->find($slug) : null;
                if (!$page) {
                    $page = $kirby->page($slug);
                }
                if (!$page || $page->intendedTemplate()->name() !== 'project') {
                    return $kirby->response()->redirect('/panel/login');
                }

                // Editor access REQUIRED — anything less is refused.
                if ($page->shareTokenAccess($token) !== 'editor') {
                    return $kirby->response()->redirect('/panel/login');
                }

                // Check if user already exists for this token
                $user = $kirby->users()->filterBy('role', 'collaborator')->filterBy('share_token', $token)->first();
                if ($user) {
                    return snippet('gh-editor-signup', [
                        'page'      => $page,
                        'token'     => $token,
                        'slug'      => $slug,
                        'errors'    => [],
                        'form_data' => [],
                        'status'    => 'used',
                    ], true);
                }

                $email           = trim((string) get('email'));
                $name            = trim((string) get('name'));
                $password        = (string) get('password');
                $passwordConfirm = (string) get('password_confirm');

                $errors = [];
                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $errors[] = 'Adresse email invalide.';
                }
                if ($name === '') {
                    $errors[] = 'Le nom est requis.';
                }
                if (strlen($password) < 8) {
                    $errors[] = 'Le mot de passe doit faire au moins 8 caractères.';
                }
                if ($password !== $passwordConfirm) {
                    $errors[] = 'Les mots de passe ne correspondent pas.';
                }
                if (empty($errors) && $kirby->users()->find($email)) {
                    $errors[] = 'Un compte existe déjà avec cette adresse. Connectez-vous au panneau pour y accéder.';
                }

                if (!empty($errors)) {
                    return snippet('gh-editor-signup', [
                        'page'      => $page,
                        'token'     => $token,
                        'slug'      => $slug,
                        'errors'    => $errors,
                        'form_data' => ['email' => $email, 'name' => $name],
                        'status'    => 'active',
                    ], true);
                }

                // Create user
                try {
                    $kirby->impersonate('kirby');
                    $user = $kirby->users()->create([
                        'name'     => $name,
                        'email'    => $email,
                        'password' => $password,
                        'role'     => 'collaborator',
                        'language' => 'fr',
                    ]);
                    $user->update([
                        'scoped_page' => $page->id(),
                        'share_token' => $token,
                    ]);
                    $kirby->impersonate();
                } catch (\Throwable $e) {
                    return snippet('gh-editor-signup', [
                        'page'      => $page,
                        'token'     => $token,
                        'slug'      => $slug,
                        'errors'    => ['Erreur lors de la création du compte : ' . $e->getMessage()],
                        'form_data' => ['email' => $email, 'name' => $name],
                        'status'    => 'active',
                    ], true);
                }

                // Auto-login the new user
                try {
                    $user->loginPasswordless();
                } catch (\Throwable $e) {
                }

                $panelId = str_replace('/', '+', $page->id());
                return $kirby->response()->redirect('/panel/pages/' . $panelId . '?tab=overview');
            }
        ],

        // STRUCTURED ZIP DOWNLOAD. Packages all project files into a ZIP with
        // category subfolders and project-slug-prefixed filenames so the
        // recipient gets a self-describing archive instead of Kirby's flat
        // file directory. Requires a logged-in panel user OR a viewer/editor
        // share token — visit-only tokens are denied (they have no file access).
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
                // viewer/editor share token for THIS page.
                $user = $kirby->user();
                if (!$user) {
                    $access = $page->shareTokenAccess(get('key'));
                    if ($access !== 'viewer' && $access !== 'editor') {
                        $kirby->response()->code(403);
                        return 'Accès refusé.';
                    }
                } elseif (in_array($user->role()->name(), ['collaborator', 'viewer'], true)) {
                    // Scoped account: confirm this is the project they were granted.
                    // Without this, a viewer scoped to project A could download
                    // project B's full file archive.
                    $scoped = $user->scoped_page()->value();
                    if ($scoped !== $page->id() && !str_starts_with($page->id(), $scoped . '/')) {
                        $kirby->response()->code(403);
                        return 'Accès refusé.';
                    }
                }

                if (!class_exists('ZipArchive')) {
                    header('HTTP/1.1 500 Internal Server Error');
                    echo 'ZipArchive non disponible sur ce serveur.';
                    exit;
                }

                // Extension → subfolder mapping (mirrors fileKind() in JS).
                // Zip is excluded from archives since the output itself is a zip.
                $folderMap = [
                    'jpg'  => 'photos',          'jpeg' => 'photos',
                    'png'  => 'photos',           'webp' => 'photos',
                    'gif'  => 'photos',           'svg'  => 'photos',
                    'tif'  => 'photos',           'tiff' => 'photos',
                    'bmp'  => 'photos',           'avif' => 'photos',
                    'obj'  => 'modeles-3d',       'glb'  => 'modeles-3d',
                    'gltf' => 'modeles-3d',       'fbx'  => 'modeles-3d',
                    'stl'  => 'modeles-3d',       'mtl'  => 'modeles-3d',
                    'dae'  => 'modeles-3d',       '3ds'  => 'modeles-3d',
                    'ply'  => 'nuage-de-points',  'las'  => 'nuage-de-points',
                    'laz'  => 'nuage-de-points',  'e57'  => 'nuage-de-points',
                    'pts'  => 'nuage-de-points',  'pcd'  => 'nuage-de-points',
                    'xyz'  => 'nuage-de-points',
                    'pdf'  => 'documents',        'doc'  => 'documents',
                    'docx' => 'documents',        'odt'  => 'documents',
                    'rtf'  => 'documents',        'txt'  => 'documents',
                    'md'   => 'documents',
                    'json' => 'donnees',          'csv'  => 'donnees',
                    'xml'  => 'donnees',          'yml'  => 'donnees',
                    'yaml' => 'donnees',
                    'rar'  => 'archives',         '7z'   => 'archives',
                    'tar'  => 'archives',         'gz'   => 'archives',
                    'mp4'  => 'videos',           'mov'  => 'videos',
                    'webm' => 'videos',           'avi'  => 'videos',
                    'mkv'  => 'videos',
                ];

                $slug = $page->slug();
                $tmp  = tempnam(sys_get_temp_dir(), 'gh_pkg_');
                $zip  = new \ZipArchive();

                if ($zip->open($tmp, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
                    header('HTTP/1.1 500 Internal Server Error');
                    echo 'Impossible de créer l\'archive.';
                    exit;
                }

                // Root-level README with basic project metadata.
                $readmeLines = [
                    (string) $page->title(),
                    str_repeat('=', mb_strlen((string) $page->title())),
                    '',
                    'Exporté depuis GoHéritage le ' . date('d/m/Y à H:i'),
                ];
                if ($page->location()->isNotEmpty()) {
                    $readmeLines[] = 'Localisation : ' . $page->location();
                }
                if ($page->date()->isNotEmpty()) {
                    $readmeLines[] = 'Date de numérisation : ' . $page->date();
                }
                if ($page->architect()->isNotEmpty()) {
                    $readmeLines[] = 'Architecte : ' . $page->architect();
                }
                $zip->addFromString($slug . '/README.txt', implode("\n", $readmeLines) . "\n");

                // Give PHP enough time for large archives (point clouds etc.).
                @set_time_limit(300);

                // Track used names to avoid collisions if two files share a stem.
                $usedNames = [];

                foreach ($page->files() as $file) {
                    $absPath = $file->root();
                    if (!is_readable($absPath)) continue;

                    $ext    = strtolower($file->extension());
                    $folder = $folderMap[$ext] ?? 'autres';

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

                $zip->close();

                $downloadName = $slug . '_dossier.zip';
                $size         = filesize($tmp);

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
                    // read-only viewer or a collaborator scoped to another project)
                    // could change any page's visibility. Require that the real
                    // current user actually holds update rights on THIS page.
                    $actor = $kirby->user();
                    if (!$actor || $page->permissions()->cannot('update')) {
                        $kirby->response()->code(403);
                        return ['status' => 'error', 'message' => 'Accès refusé.'];
                    }
                    // Scoped collaborator: confirm this is their granted project.
                    if ($actor->role()->name() === 'collaborator') {
                        $scoped = $actor->scoped_page()->value();
                        if ($scoped !== $page->id() && !str_starts_with($page->id(), $scoped . '/')) {
                            $kirby->response()->code(403);
                            return ['status' => 'error', 'message' => 'Accès refusé.'];
                        }
                    }

                    $kirby->impersonate('kirby');

                    $body       = $kirby->request()->body();
                    $visibility = $body->get('visibility');

                    if (!in_array($visibility, ['private', 'link', 'public'], true)) {
                        return ['status' => 'error', 'message' => 'Invalid visibility value'];
                    }

                    // Map our 3-tier to Kirby's 2-tier status
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
                'shareLinks' => function () {
                    $links = [];
                    $structure = $this->model()->share_links()->toStructure();
                    foreach ($structure as $link) {
                        $links[] = [
                            // StructureObject::id() is a reserved method that
                            // returns the row id as a STRING (not a Field), so
                            // it must NOT be called with ->value().
                            'id'               => (string) $link->id(),
                            'token'            => $link->token()->value(),
                            'label'            => $link->label()->value(),
                            'access'           => $link->access()->or('visit')->value(),
                            'visible_sections' => $link->visible_sections()->split(','),
                        ];
                    }
                    return $links;
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

        'route:before' => function ($route, $path, $method) {
            $kirby = kirby();
            $user  = $kirby->user();
            if (!$user) return;

            $role = $user->role()->name();
            // Only scoped roles need token validation on every request.
            if ($role !== 'collaborator' && $role !== 'viewer') return;

            $scoped     = $user->scoped_page()->value();
            $shareToken = $user->share_token()->value();
            $scopedPage = $scoped ? $kirby->page($scoped) : null;

            // The expected access level differs by role.
            $expectedAccess = ($role === 'collaborator') ? 'editor' : 'viewer';

            if (!$scopedPage || empty($shareToken)
                || $scopedPage->shareTokenAccess($shareToken) !== $expectedAccess) {
                $user->logout();
                if (str_starts_with($path, 'panel')) {
                    go('/panel/login');
                }
            }
        },

        // Generate a per-page share token and default to "link" visibility
        // on creation so it is not publicly exposed by default.
        'page.create:after' => function ($page) {
            if ($page->intendedTemplate()->name() === 'project') {
                try {
                    $token = bin2hex(random_bytes(16));
                    $page->update([
                        'share_token' => $token,
                        'visibility'  => 'link',
                    ]);
                } catch (\Throwable $e) {
                    // Silent — the fields will be backfilled on next save.
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
                    } else {
                        $v = $newPage->visibility()->value();
                        if ($v === 'private' || empty($v)) {
                            $newPage->update(['visibility' => 'link']);
                        }
                    }
                } catch (\Throwable $e) {
                }
            }
        },

        // Backfill missing tokens on update so projects that existed before
        // this plugin shipped get a token the first time they're touched.
        'page.update:before' => function ($page) {
            gh_guard_collaborator_scope($page);
            if (
                $page->intendedTemplate()->name() === 'project'
                && $page->share_token()->isEmpty()
            ) {
                try {
                    $token = bin2hex(random_bytes(16));
                    $page->content()->update(['share_token' => $token]);
                } catch (\Throwable $e) {
                    // Silent — same reasoning as above.
                }
            }
        },

        // ── Collaborator scope guards ───────────────────────────────────
        // Block scoped collaborator accounts from mutating anything outside
        // the single project they were shared.
        'page.changeStatus:before' => function ($page, $status, $position = null) {
            gh_guard_collaborator_scope($page);
        },
        'page.delete:before' => function ($page, $force = false) {
            gh_guard_collaborator_scope($page);
        },
        'page.duplicate:before' => function ($page) {
            gh_guard_collaborator_scope($page);
        },
        'file.create:before' => function ($file) {
            gh_guard_collaborator_scope($file);
        },
        'file.update:before' => function ($newFile, $oldFile) {
            gh_guard_collaborator_scope($newFile);
        },
        'file.replace:before' => function ($newFile, $oldFile) {
            gh_guard_collaborator_scope($newFile);
        },
        'file.delete:before' => function ($file, $force = false) {
            gh_guard_collaborator_scope($file);
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
    ],

    // ── Page methods for templates and controllers ─────────────────────────
    'pageMethods' => [

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
        'visibilityResolved' => function () {
            $v = $this->visibility()->value();
            if ($v === 'public' || $v === 'link' || $v === 'private') {
                return $v;
            }
            // Safe default: a listed page with no explicit visibility is
            // treated as link-only (NOT public). Pages are only listed on the
            // public map when visibility is explicitly 'public'.
            return $this->isListed() ? 'link' : 'private';
        },

        // Resolve the share-link row that a given token belongs to (or null).
        // Each row in the `share_links` structure carries its own token +
        // access level, so the token itself — not the URL path — determines
        // what a recipient may do.
        'shareLinkByToken' => function (?string $token = null) {
            if (empty($token)) {
                return null;
            }
            foreach ($this->share_links()->toStructure() as $link) {
                $lt = $link->token()->value();
                if (!empty($lt) && hash_equals($lt, (string) $token)) {
                    return $link;
                }
            }
            return null;
        },

        // Access level granted BY A TOKEN, hierarchical:
        //   'editor'  → panel edit login + dossier + visit
        //   'dossier' → read-only dossier + visit
        //   'visit'   → Matterport-style project page only
        //   null      → token unknown / invalid
        // This is the single source of truth that stops a low-privilege link
        // from being escalated by swapping the URL path.
        'shareTokenAccess' => function (?string $token = null) {
            if (empty($token)) {
                return null;
            }
            $link = $this->shareLinkByToken($token);
            if ($link) {
                $access = $link->access()->or('visit')->value();
                // 'dossier' is the legacy name for viewer — map it transparently
                // so old share links continue to work without a data migration.
                if ($access === 'dossier') $access = 'viewer';
                return in_array($access, ['visit', 'viewer', 'editor'], true) ? $access : 'visit';
            }
            // Legacy page-wide token predates per-link access → visit only.
            if ($this->share_token()->isNotEmpty() && hash_equals($this->share_token()->value(), (string) $token)) {
                return 'visit';
            }
            return null;
        },

        'isPubliclyVisible' => function () {
            return $this->visibilityResolved() === 'public';
        },

        'isLinkOnly' => function () {
            return $this->visibilityResolved() === 'link';
        },

        // May the project page (Matterport-style visit) be viewed with this
        // token? Any token that resolves to a valid access level grants the
        // visit; finer control over WHAT is shown lives in sectionVisible().
        'canBeViewedWithToken' => function (?string $token = null) {
            $v = $this->visibilityResolved();
            if ($v === 'public') {
                return true;
            }
            if ($v === 'link' && $token) {
                return $this->shareTokenAccess($token) !== null;
            }
            return false;
        },

        // Per-section visibility for the public project page. Public pages use
        // the page-level `visible_sections`; link visits use the matched
        // share-link's own `visible_sections` (legacy page-wide token falls
        // back to the page-level list).
        'sectionVisible' => function (string $section) {
            $v = $this->visibilityResolved();
            if ($v === 'public') {
                $field = $this->visible_sections();
                if ($field->isEmpty()) {
                    return true;
                }
                return in_array($section, $field->split(','), true);
            }

            if ($v === 'link') {
                $token = get('key');
                if (!$token) {
                    return false;
                }
                $link = $this->shareLinkByToken($token);
                if ($link) {
                    $field = $link->visible_sections();
                    if ($field->isEmpty()) {
                        return false;
                    }
                    return in_array($section, $field->split(','), true);
                }
                // Legacy page-wide token → page-level visible_sections.
                if ($this->share_token()->isNotEmpty() && hash_equals($this->share_token()->value(), (string) $token)) {
                    $field = $this->visible_sections();
                    if ($field->isEmpty()) {
                        return true;
                    }
                    return in_array($section, $field->split(','), true);
                }
            }
            return false;
        },
    ],
]);
