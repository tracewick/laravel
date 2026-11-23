<?php

declare(strict_types=1);

// Publish with php artisan vendor:publish --tag=tracewick-config.
// If configuration is cached, run php artisan config:cache after changing these settings.
return [
    // Set TRACEWICK_LARAVEL_ENABLED=false to disable the Laravel integration.
    // The core also honors TRACEWICK_ENABLED=0 and an existing TRACEWICK_DISABLE_FILE.
    // Set core kill switches in the PHP worker's environment when using cached configuration.
    'enabled' => env('TRACEWICK_LARAVEL_ENABLED', true),

    // Set to false when you prepend ProfileRequest to the global middleware stack yourself.
    'register_middleware' => true,

    // Enable profiling for ordinary Artisan commands. Off by default.
    // Long-running runners such as queue:work, Horizon, and Octane are excluded.
    'profile_console' => env('TRACEWICK_PROFILE_CONSOLE', false),

    // Enable per-attempt profiling in queue:work and Horizon. Off by default.
    // Jobs on the sync driver stay within the current request, command, or job profile.
    'profile_queue' => env('TRACEWICK_PROFILE_QUEUE', false),

    // Service name attached to every profile. Start with a letter or digit and use only
    // ASCII letters, digits, dots, underscores, or dashes. The maximum is 256 characters.
    'application_name' => env('TRACEWICK_APPLICATION', 'laravel'),

    // Local directory writable by PHP and accessible to one spool reader.
    // Set the same TRACEWICK_SPOOL_DIR for tracewick-drain or the Go agent in its own environment.
    'spool_directory' => env('TRACEWICK_SPOOL_DIR', '/var/spool/tracewick'),

    // Fraction of eligible requests, commands, and job attempts to profile, from 0 to 1. The default is 10%.
    'sample_rate' => env('TRACEWICK_SAMPLE_RATE', 0.1),

    // Static string-to-string labels such as ['env' => 'production'].
    // The bridge sets route and method for HTTP, command for Artisan, and job for queue attempts.
    // Keep custom values stable and exclude request data, account IDs, and secrets.
    'base_labels' => [],
];
