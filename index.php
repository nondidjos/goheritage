<?php

// Catch the heavy model upload early and grant it the memory it needs
// before Kirby's core bootstraps and tries to process the payload
if (strpos($_SERVER['REQUEST_URI'] ?? '', '/api/goheritage/upload-overwrite') !== false) {
    ini_set('memory_limit', '1G');
    set_time_limit(3600);
    ini_set('display_errors', '0');
    register_shutdown_function(function() {
        $error = error_get_last();
        if ($error !== null) {
            file_put_contents(__DIR__ . '/site/logs/shutdown-error.log', print_r($error, true));
        }
    });
}

require __DIR__ . '/kirby/bootstrap.php';

echo (new Kirby)->render();
