<?php

/**
 * Local development overrides — merged on top of config.php.
 *
 * The base config now auto-detects local dev hostnames and flips
 * 'debug' itself, so this file is intentionally minimal. Add any
 * extra dev-only options here.
 */
return [
    'maptiler.key' => '', // set locally, never commit a real key here
];
