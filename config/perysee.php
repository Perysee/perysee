<?php

return [
    'installation_path' => storage_path('app/private/installation'),
    'environment_path' => app()->environmentFilePath(),
    'aggregate' => [
        'batch' => (int) env('PERYSEE_AGGREGATE_BATCH', 500),
        'budget_seconds' => (int) env('PERYSEE_AGGREGATE_BUDGET_SECONDS', 15),
    ],
    'raw_retention_days' => (int) env('PERYSEE_RAW_RETENTION_DAYS', 90),
    'redis_enabled' => (bool) env('PERYSEE_REDIS_ENABLED', false),
    'landing_url' => env('PERYSEE_LANDING_URL', 'http://localhost:3000'),
    'docs_url' => env('PERYSEE_DOCS_URL', 'http://localhost:3001'),
];
