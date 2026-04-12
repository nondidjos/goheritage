<?php

/**
 * The config file is optional. It accepts a return array with config options
 * article: Never include more than one return statement, all options go within this single return array
 * In this example, we set debugging to true, so that errors are displayed onscreen. 
 * This setting must be set to false in production.
 * All config options: https://getkirby.com/docs/reference/system/options
 */
use Kirby\Data\Data;

return [
    'debug' => false,
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
            'home' => [
                'icon'  => 'home',
                'label' => 'Accueil',
                'link'  => 'pages/home',
                'current' => function () {
                    return str_contains(\kirby()->request()->path()->toString(), 'pages/home');
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
            'blog' => [
                'icon'  => 'book',
                'label' => 'Blog',
                'link'  => 'pages/blog',
                'current' => function () {
                    return str_contains(\kirby()->request()->path()->toString(), 'pages/blog');
                }
            ],
            '-',
            'users',
            'system'
        ]
    ],
    'maptiler.key' => 'ooEH2b8Xfch0mEM4zarL',
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
