<?php

/**
 * The config file is optional. It accepts a return array with config options
 * Note: Never include more than one return statement, all options go within this single return array
 * In this example, we set debugging to true, so that errors are displayed onscreen. 
 * This setting must be set to false in production.
 * All config options: https://getkirby.com/docs/reference/system/options
 */
return [
    'debug' => true,
    'yaml.handler' => 'symfony', // already makes use of the more modern Symfony YAML parser: https://getkirby.com/docs/reference/system/options/yaml (will become the default in a future Kirby version)
    'hooks' => [
        'page.changeStatus:before' => function ($page, $status, $oldStatus) {
            $user = $this->user();
            // Block 'author' role from publishing (changing state to 'listed')
            if ($user && $user->role()->name() === 'author' && $status === 'listed') {
                throw new Exception('You are not allowed to publish pages. Please submit for review instead.');
            }
        }
    ]
];
