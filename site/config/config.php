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
    'debug' => true,
    'yaml.handler' => 'symfony',
    'mime' => [
        'types' => [
            'obj' => 'model/obj',
            'mtl' => 'model/mtl',
            'glb' => 'model/gltf-binary',
        ]
    ],
    // Kirby thumbs. Imagick streams big JPEGs through disk buffers which keeps
    // peak memory usage well below GD's decode-whole-image-to-RAM approach —
    // critical for 8192×4096 equirect panoramas (~128 MB of pixel data per file).
    // Falls back to GD automatically if the Imagick extension isn't loaded.
    'thumbs' => [
        'driver'  => extension_loaded('imagick') ? 'im' : 'gd',
        'quality' => 85,
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
        // Pre-generate panorama thumbs on upload so viewers never wait on a
        // cold GD/Imagick job. Two sizes: 4096 (viewable equirect) + 1024
        // (preview / dollhouse markers). Thumbs land in /media/... and are
        // served directly thereafter — identical paths to what project.php
        // asks for at render time.
        'file.create:after' => function ($file) {
            if ($file->template() !== 'panorama') return;
            if (!in_array(strtolower($file->extension()), ['jpg', 'jpeg', 'png', 'webp'])) return;
            // Matterport cube faces — already small, and thumb URLs break the
            // viewer's SKYBOX_REGEX (needs `_skybox<N>.ext` at end, no `-WIDTHx`).
            if (preg_match('/[-_]skybox[-_]?\d/i', $file->filename())) return;
            try {
                $file->thumb(['width' => 4096, 'quality' => 85, 'format' => 'jpg']);
                $file->thumb(['width' => 1024, 'quality' => 75, 'format' => 'jpg']);
            } catch (\Throwable $e) {
                error_log('[panorama warm-thumb] ' . $file->filename() . ': ' . $e->getMessage());
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
