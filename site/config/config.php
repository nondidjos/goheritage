<?php

/**
 * The config file is optional. It accepts a return array with config options
 * Note: Never include more than one return statement, all options go within this single return array
 * In this example, we set debugging to true, so that errors are displayed onscreen. 
 * This setting must be set to false in production.
 * All config options: https://getkirby.com/docs/reference/system/options
 */
use Kirby\Data\Data;

return [
    'debug' => true,
    'yaml.handler' => 'symfony',
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
                                'notes' => [
                                    'extends' => 'sections/notes'
                                ]
                            ]
                        ]
                    ]
                ];
            }
            return Data::read($kirby->root('blueprints') . '/site.yml');
        }
    ],
    'hooks' => [
        'page.create:after' => function ($page) {
            $user = $this->user();
            // Automatically assign the current user as author for new notes
            if ($user && $page->intendedTemplate()->name() === 'note') {
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
