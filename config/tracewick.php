<?php

declare(strict_types=1);

return [
    'enabled' => env('TRACEWICK_LARAVEL_ENABLED', true),
    'register_middleware' => true,
    'application_name' => env('TRACEWICK_APPLICATION', 'laravel'),
    'spool_directory' => env('TRACEWICK_SPOOL_DIR', '/var/spool/tracewick'),
    'sample_rate' => env('TRACEWICK_SAMPLE_RATE', 0.1),
    'base_labels' => [],
];
