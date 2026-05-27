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

Kirby::plugin('goheritage/project-ux', [

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

    // ── Custom panel fields ────────────────────────────────────────────────
    'fields' => [

        // Read-only display of the share URL with copy button.
        // The URL is computed server-side from the page's share_token field.
        //
        // The earlier `section-edit-control` field has been retired. Per-section
        // edit is now handled by a DOM injector in index.js that tags every
        // `.k-fields-section` on a project page and appends a floating edit
        // chip — no blueprint plumbing, no field-name collisions, no Kirby
        // chrome around the button.
        'share-link' => [
            'props' => [
                'label' => function ($label = null) { return $label; },
                'help'  => function ($help = null)  { return $help; },
            ],
            'computed' => [
                'shareUrl' => function () {
                    $page  = $this->model();
                    $token = $page->share_token()->value();
                    if (!$token) {
                        return null;
                    }
                    return $page->url() . '?key=' . $token;
                },
            ],
        ],
    ],

    // ── Hooks ──────────────────────────────────────────────────────────────
    'hooks' => [

        // Generate a per-page share token on creation so the share-link
        // field has something to display the moment the user opens the page.
        'page.create:after' => function ($page) {
            if (
                $page->intendedTemplate()->name() === 'project'
                && $page->share_token()->isEmpty()
            ) {
                try {
                    $token = bin2hex(random_bytes(16));
                    $page->update(['share_token' => $token]);
                } catch (\Throwable $e) {
                    // Silent — the field will be backfilled on next save.
                }
            }
        },

        // Backfill missing tokens on update so projects that existed before
        // this plugin shipped get a token the first time they're touched.
        'page.update:before' => function ($page) {
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
            return $this->isListed() ? 'public' : 'private';
        },

        'isPubliclyVisible' => function () {
            return $this->visibilityResolved() === 'public';
        },

        'isLinkOnly' => function () {
            return $this->visibilityResolved() === 'link';
        },

        // Token check using hash_equals to avoid timing attacks.
        'canBeViewedWithToken' => function (?string $token = null) {
            $v = $this->visibilityResolved();
            if ($v === 'public') {
                return true;
            }
            if ($v === 'link' && $token && $this->share_token()->isNotEmpty()) {
                return hash_equals($this->share_token()->value(), (string) $token);
            }
            return false;
        },

        // Per-section visibility — defaults to "all visible" when the field
        // has never been touched (backward-compat for pre-plugin projects).
        'sectionVisible' => function (string $section) {
            $field = $this->visible_sections();
            if ($field->isEmpty()) {
                return true;
            }
            $list = $field->split(',');
            return in_array($section, $list, true);
        },
    ],
]);
