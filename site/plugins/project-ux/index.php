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
        // str_starts_with makes the prefix check explicit: the collaborator's
        // scoped page itself, or a descendant under "<scoped>/". The trailing
        // slash is load-bearing — without it "map/foo" would also match the
        // sibling "map/foo-2".
        if ($id !== $scoped && !str_starts_with($id, $scoped . '/')) {
            throw new \Kirby\Exception\PermissionException('Accès limité à votre projet partagé.');
        }
    }
}

// READ-access gate for a project page's content, shared by the gated asset
// route (gh/file) and the ZIP download (gh/download) so the authorization
// lives in exactly one place and can't drift between them:
//   • A panel user is allowed; a scoped collaborator/viewer only for THEIR
//     own project subtree.
//   • Otherwise a share token decides. `$requireDownload` is the one
//     difference: downloading the file archive needs viewer/editor rights,
//     whereas merely viewing an asset is allowed for any token that can see
//     the page (incl. a visit-only link).
if (!function_exists('gh_requester_may_access')) {
    function gh_requester_may_access($page, bool $requireDownload = false): bool
    {
        $user = kirby()->user();
        if ($user) {
            if (in_array($user->role()->name(), ['collaborator', 'viewer'], true)) {
                $scoped = $user->scoped_page()->value();
                return $scoped === $page->id() || str_starts_with($page->id(), $scoped . '/');
            }
            return true; // admin / author / editor accounts
        }
        if ($requireDownload) {
            return in_array($page->shareTokenAccess(get('key')), ['viewer', 'editor'], true);
        }
        return $page->canBeViewedWithToken(get('key'));
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
                    // regardless of whether the current panel user is a
                    // scoped collaborator/viewer with limited page access.
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
            $url = '/gh/file/' . str_replace('/', '+', $this->id())
                 . '/' . rawurlencode($file->filename());
            $key = get('key');
            return $key ? $url . '?key=' . urlencode($key) : $url;
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
