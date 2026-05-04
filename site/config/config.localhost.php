<?php

/**
 * Local development overrides — merged on top of config.php when Kirby
 * detects a local-loopback hostname (127.0.0.1, ::1, localhost). These
 * settings should NEVER ship to production.
 */
return [
    'debug' => true,
    // Let Kirby auto-detect the URL locally (Herd, php -S, etc.) instead of
    // forcing the production subdomain. Empty string == auto-detect.
    'url'   => '/',
];
