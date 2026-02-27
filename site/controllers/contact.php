<?php

return function ($kirby, $page) {

    $alert = null;
    $success = false;
    $data = [];

    if ($kirby->request()->is('POST') && get('submit')) {

        // honeypot
        if (empty(get('website')) === false) {
            go($page->url());
            exit;
        }

        $data = [
            'name' => get('name'),
            'email' => get('email'),
            'message' => get('message'),
        ];

        $rules = [
            'name' => ['required', 'minLength' => 2],
            'email' => ['required', 'email'],
            'message' => ['required', 'minLength' => 10, 'maxLength' => 3000],
        ];

        $messages = [
            'name' => 'Please enter your name (at least 2 characters).',
            'email' => 'Please enter a valid email address.',
            'message' => 'Please enter a message between 10 and 3000 characters.',
        ];

        if ($invalid = invalid($data, $rules, $messages)) {
            $alert = $invalid;
        } else {
            try {
                $kirby->email([
                    'template' => 'contact',
                    'from' => 'noreply@goheritage.fr',
                    'replyTo' => $data['email'],
                    'to' => $page->email()->value() ?: 'contact@goheritage.fr',
                    'subject' => esc($data['name']) . ' sent a message via GoHéritage',
                    'data' => [
                        'sender' => esc($data['name']),
                        'email' => esc($data['email']),
                        'message' => esc($data['message']),
                    ],
                ]);
            } catch (Exception $error) {
                if (option('debug')) {
                    $alert['error'] = 'The message could not be sent: <strong>' . $error->getMessage() . '</strong>';
                } else {
                    $alert['error'] = 'The message could not be sent. Please try again later.';
                }
            }

            if (empty($alert)) {
                $success = true;
                $data = [];
            }
        }
    }

    return [
        'alert' => $alert,
        'data' => $data,
        'success' => $success,
    ];

};
