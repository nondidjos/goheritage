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

        $mode = get('mode') === 'owner' ? 'owner' : 'visitor';

        $data = [
            'name'          => get('name'),
            'email'         => get('email'),
            'message'       => get('message'),
            'mode'          => $mode,
            'subject'       => get('subject'),
            'property'      => get('property'),
            'region'        => get('region'),
            'property_type' => is_array(get('property_type')) ? implode(', ', get('property_type')) : '',
            'activities'    => is_array(get('activities')) ? implode(', ', get('activities')) : '',
        ];

        $rules = [
            'name' => ['required', 'minLength' => 2],
            'email' => ['required', 'email'],
            'message' => ['required', 'minLength' => 10, 'maxLength' => 3000],
        ];
        // Owner side requires a property name on top of the basic rules.
        if ($mode === 'owner') {
            $rules['property'] = ['required', 'minLength' => 2];
        }

        // validation messages in french
        $messages = [
            'name'     => 'Veuillez entrer votre nom (au moins 2 caractères).',
            'email'    => 'Veuillez entrer une adresse e-mail valide.',
            'message'  => 'Veuillez entrer un message entre 10 et 3000 caractères.',
            'property' => 'Veuillez indiquer le nom de votre propriété.',
        ];

        if ($invalid = invalid($data, $rules, $messages)) {
            $alert = $invalid;
        } else {
            try {
                $subjectPrefix = $mode === 'owner'
                    ? '[Propriétaire] '
                    : '[Visiteur] ';
                $kirby->email([
                    'template' => 'contact',
                    'from'     => 'noreply@goheritage.fr',
                    'replyTo'  => $data['email'],
                    'to'       => $page->email()->value() ?: 'contact@goheritage.fr',
                    'subject'  => $subjectPrefix . esc($data['name']) . ' — GoHéritage',
                    'data'     => [
                        'sender'        => esc($data['name']),
                        'email'         => esc($data['email']),
                        'message'       => esc($data['message']),
                        'mode'          => $mode,
                        'subject'       => esc($data['subject'] ?? ''),
                        'property'      => esc($data['property'] ?? ''),
                        'region'        => esc($data['region'] ?? ''),
                        'property_type' => esc($data['property_type'] ?? ''),
                        'activities'    => esc($data['activities'] ?? ''),
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
