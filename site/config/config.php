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
    // Use root-relative URLs everywhere. Kirby's url() helper produces paths
    // like "/assets/css/app.css" which work correctly on any hostname (local
    // dev, staging, production) without ever hardcoding a domain. This also
    // avoids the CORS trap of generating cross-origin asset URLs in HTML.
    'url' => '/',
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
