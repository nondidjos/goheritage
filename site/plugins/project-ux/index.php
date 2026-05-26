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
