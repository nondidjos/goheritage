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
        if (!$user || $user->role()->name() !== 'collaborator') {
            return;
        }
        $scoped = $user->scoped_page()->value();
        if (empty($scoped)) {
            // No scope recorded → deny all writes rather than allow all.
            throw new \Kirby\Exception\PermissionException('Compte de partage non lié à un projet.');
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

        // EDITOR share login. Only an editor-level token grants this — a
        // visit/dossier token cannot escalate by hitting this path. Each
        // distinct token gets its OWN scoped `collaborator` account that is
        // restricted (via role permissions + write-guard hooks + a scoped
        // panel menu) to the single shared project. Revoking the share link
        // immediately invalidates that account's access.
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

                // Editor access REQUIRED — anything less is refused.
                if ($page->shareTokenAccess($token) !== 'editor') {
                    return $kirby->response()->redirect('/panel/login');
                }

                $kirby->impersonate('kirby');

                // Unique account per token (not a shared editor@ login).
                $tokenHash = substr(hash('sha256', (string) $token), 0, 12);
                $email     = 'share-' . $tokenHash . '@goheritage.invalid';

                $user = $kirby->users()->find($email);
                if (!$user) {
                    try {
                        $user = $kirby->users()->create([
                            'email'    => $email,
                            'password' => bin2hex(random_bytes(16)),
                            'role'     => 'collaborator',
                            'name'     => 'Collaborateur — ' . $page->title()->value(),
                        ]);
                        $user->update(['scoped_page' => $page->id()]);
                    } catch (\Throwable $e) {
                        return 'Erreur de création de compte collaborateur: ' . $e->getMessage();
                    }
                }

                $kirby->session()->set('kirby.userId', $user->id());

                $panelId = str_replace('/', '+', $page->id());
                return $kirby->response()->redirect('/panel/pages/' . $panelId . '?tab=overview');
            }
        ],

        // READ-ONLY DOSSIER. A login-free, session-free page that presents the
        // project's full content + a downloadable file list. Requires a
        // dossier- or editor-level token (or a logged-in panel user). Scoped
        // to exactly one project — there is no navigation to other pages and
        // no Kirby panel exposure, so it is safe to send to anyone.
        [
            'pattern' => 'dossier/(:any)',
            'action'  => function (string $slug) {
                $kirby = kirby();

                $map  = $kirby->page('map');
                $page = $map ? $map->children()->find($slug) : null;
                if (!$page) {
                    $page = $kirby->page($slug);
                }
                if (!$page || $page->intendedTemplate()->name() !== 'project') {
                    $kirby->response()->code(404);
                    return $kirby->site()->errorPage()->render();
                }

                $user = $kirby->user();
                if (!$user) {
                    $access = $page->shareTokenAccess(get('key'));
                    if ($access !== 'dossier' && $access !== 'editor') {
                        // 404 (not 403) so private pages don't leak existence.
                        $kirby->response()->code(404);
                        return $kirby->site()->errorPage()->render();
                    }
                }

                // Set the page as the current request context so snippets
                // (header/footer) and helpers resolve correctly.
                $kirby->site()->visit($page);

                return snippet('dossier', [
                    'page'     => $page,
                    'shareKey' => get('key'),
                ], true);
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
                    $kirby->impersonate('kirby');

                    // Decode the panel-style ID (+ → /)
                    $pageId = str_replace('+', '/', $encodedId);
                    $page   = $kirby->page($pageId);

                    if (!$page) {
                        return ['status' => 'error', 'message' => 'Page not found: ' . $pageId];
                    }

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

                    // Gallery thumbnails (fall back to page images, like the
                    // public template, so something shows even before the
                    // gallery field is curated).
                    $gallery = $page->gallery()->toFiles();
                    if ($gallery->count() === 0) {
                        $gallery = $page->images()
                            ->filterBy('extension', 'in', ['jpg', 'jpeg', 'png', 'webp'])
                            ->sortBy('sort');
                    }
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
                    $images = [];
                    foreach ($this->model()->images() as $file) {
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
                    return $this->model()->gallery()->toFiles()->count();
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

    // ── Page methods for templates and controllers ─────────────────────────
    'pageMethods' => [

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
                return in_array($access, ['visit', 'dossier', 'editor'], true) ? $access : 'visit';
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
