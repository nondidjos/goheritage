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

        // validation messages in french
        $messages = [
            'name' => 'Veuillez entrer votre nom (au moins 2 caractères).',
            'email' => 'Veuillez entrer une adresse e-mail valide.',
            'message' => 'Veuillez entrer un message entre 10 et 3000 caractères.',
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
                    'subject' => esc($data['name']) . ' a envoyé un message via GoHéritage',
                    'data' => [
                        'sender' => esc($data['name']),
                        'email' => esc($data['email']),
                        'message' => esc($data['message']),
                    ],
                ]);
            } catch (Exception $error) {
                if (option('debug')) {
                    $alert['error'] = 'Le message n\'a pas pu être envoyé : <strong>' . $error->getMessage() . '</strong>';
                } else {
                    $alert['error'] = 'Le message n\'a pas pu être envoyé. Veuillez réessayer plus tard.';
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
