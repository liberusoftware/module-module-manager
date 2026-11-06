<?php

return [
    'paths' => [base_path('modules')],
    // Installed packages are disabled by default. The host application owns its explicit selection.
    'enabled' => array_values(array_filter(explode(',', (string) env('MODULES_ENABLED', '')))),
    'disabled' => array_values(array_filter(explode(',', (string) env('MODULES_DISABLED', '')))),
    // Leave a failing module out and report it, rather than stopping the boot. Off by default.
    'isolate_failures' => (bool) env('MODULES_ISOLATE_FAILURES', false),
    // With isolate_failures: modules that must load and cannot be disabled. A failure among these still stops the boot.
    'required' => [],
    'cache' => (bool) env('MODULES_CACHE', false),
    'cache_path' => base_path('bootstrap/cache/liberu-modules.php.cache'),
];
