<?php

return [
    'version' => '0.1.1',
    'modules_path' => env('PERYSEE_MODULES_PATH', storage_path('app/modules')),
    'update' => [
        'check' => (bool) env('PERYSEE_UPDATE_CHECK', true),
        'repository' => env('PERYSEE_UPDATE_REPOSITORY', 'Perysee/perysee'),
    ],
    // Set PERYSEE_MODULES=false to start Perysee without loading any module (recovery when a module breaks the instance).
    'modules_enabled' => (bool) env('PERYSEE_MODULES', true),
    // A module that fails this many times within `window` seconds is put in quarantine (disabled, data kept).
    'module_failures' => [
        'window' => 600,
        'threshold' => ['stable' => 20, 'beta' => 10, 'experimental' => 5],
    ],
    'modules_backup_path' => env('PERYSEE_MODULES_BACKUP_PATH', storage_path('app/modules-backup')),
    // The public catalog of free official modules. PERYSEE_MODULE_CATALOG=false turns every call to it off.
    'module_catalog' => [
        'enabled' => (bool) env('PERYSEE_MODULE_CATALOG', true),
        'url' => env('PERYSEE_MODULE_CATALOG_URL', 'https://api.perysee.com/api/v1/catalog/modules'),
        'keys_path' => resource_path('module-keys.json'),
    ],
    'module_uploads' => (bool) env('PERYSEE_MODULE_UPLOADS', true),
    'module_upload_max_kb' => (int) env('PERYSEE_MODULE_UPLOAD_MAX_KB', 20480),
    'geoip_path' => env('PERYSEE_GEOIP_PATH'),
    'trusted_proxies' => array_values(array_filter(array_map('trim', explode(',', env('PERYSEE_TRUSTED_PROXIES', ''))))),
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
