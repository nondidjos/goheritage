<?php

/**
 * The config file is optional. It accepts a return array with config options
 * article: Never include more than one return statement, all options go within this single return array
 * In this example, we set debugging to true, so that errors are displayed onscreen. 
 * This setting must be set to false in production.
 * All config options: https://getkirby.com/docs/reference/system/options
 */
use Kirby\Data\Data;

// Detect local dev environments so we can flip a few options at request
// time without depending on host-specific config files (which have proven
// brittle when the dev hostname doesn't match exactly).
$ghHost = $_SERVER['HTTP_HOST'] ?? '';
$ghIsLocalDev = (
    $ghHost === 'localhost'
    || str_starts_with($ghHost, '127.')
    || str_starts_with($ghHost, '192.168.')
    || str_ends_with($ghHost, '.test')
    || str_ends_with($ghHost, '.local')
);

return [
    'debug' => $ghIsLocalDev,
    // `'url' => '*'` does double duty in Kirby 5:
    //   1. It is the `allowed` hosts list for environment detection. With a
    //      wildcard, Kirby auto-detects the current host from the request,
    //      which is what lets `site/config/config.<host>.php` (per-host
    //      override files) actually load. A literal `'/'` here parses as a
    //      URI with no host — $environment->host() ends up null and the
    //      host-specific config files (where API keys like maptiler.key
    //      live) are silently skipped, leaving `option('maptiler.key')`
    //      empty in production.
    //   2. It tells url() to derive the base URL from the request — so
    //      `url('assets/...')` is a same-origin absolute URL on every host,
    //      with no CORS pitfalls.
    'url' => '*',
    'yaml.handler' => 'symfony',
    'mime' => [
        'types' => [
            'obj' => 'model/obj',
            'mtl' => 'model/mtl',
            'glb' => 'model/gltf-binary',
        ]
    ],
    'auth' => [
        'methods' => [
            'password' => ['min' => 8]
        ]
    ],

    // ── SMTP / email delivery ──────────────────────────────────────────
    // Uncomment + fill in once the production domain is live and SMTP
    // credentials are issued (Lightsail blocks port 25 outbound, so SES /
    // SendGrid / Mailgun is the realistic path). The invite-system's email
    // button reads `email.transport` and refuses to send when null, so
    // leaving this commented keeps the "copy link manually" workflow.
    //
    // 'email' => [
    //     'from'      => 'noreply@goheritage.eu',
    //     'fromName'  => 'GoHéritage',
    //     'transport' => [
    //         'type'     => 'smtp',
    //         'host'     => 'email-smtp.eu-west-3.amazonaws.com', // AWS SES
    //         'port'     => 587,
    //         'security' => 'tls',
    //         'auth'     => true,
    //         'username' => 'AKIA…',
    //         'password' => '…',
    //     ],
    // ],

    'blueprints' => [
        'site' => function ($kirby) {
            $user = $kirby->user();
            if ($user && $user->role()->name() === 'author') {
                return [
                            'title' => 'Dashboard',
                            'columns' => [
                                [
                                    'width' => '1/1',
                                    'sections' => [
                                        'blog' => [
                                            'extends' => 'sections/blog'
                                        ]
                                    ]
                                ]
                            ]
                        ];
            }
            return Data::read($kirby->root('blueprints') . '/site.yml');
        }
    ],
    'panel' => [
        'menu' => [
            'site' => [
                'icon' => 'cog',
                'label' => 'Site',
                'link' => 'site',
                'current' => function () {
                    return str_contains(\kirby()->request()->path()->toString(), 'site');
                }
            ],
            'projects' => [
                'icon'  => 'box',
                'label' => 'Carte',
                'link'  => 'pages/map',
                'current' => function () {
                    return str_contains(\kirby()->request()->path()->toString(), 'pages/map');
                }
            ],
            // Invitations no longer ship as a separate sidebar item — they
            // live under the Users area (registered by the invite-system
            // plugin as an additional view + opened via a dialog from the
            // users page header). Pending users = invitations conceptually.
            '-',
            'users',
            'system'
        ]
    ],
    'maptiler.key' => '', // set in host-specific config (config.goheritage.test.php / config.localhost.php / config.<host>.php)
    'hooks' => [
        // File overwrite is handled by the custom API route in the
        // model-converter plugin (goheritage/upload-overwrite) which calls
        // $existing->replace() — bypassing FileRules::notExistingFile().

        'page.create:after' => function ($page) {
            $user = $this->user();
            // Automatically assign the current user as author for new blog
            if ($user && $page->intendedTemplate()->name() === 'article') {
                $page->update([
                            'author' => $user->email()
                        ]);
            }
        },
        'page.changeStatus:before' => function ($page, $status, $oldStatus) {
            $user = $this->user();
            // Block 'author' role from publishing (changing state to 'listed')
            if ($user && $user->role()->name() === 'author' && $status === 'listed') {
                throw new Exception('You are not allowed to publish pages. Please submit for review instead.');
            }
        }
    ]
];
