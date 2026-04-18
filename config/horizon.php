<?php

use Illuminate\Support\Str;

return [

    /*
    |--------------------------------------------------------------------------
    | Horizon Name
    |--------------------------------------------------------------------------
    */

    'name' => env('HORIZON_NAME'),

    /*
    |--------------------------------------------------------------------------
    | Horizon Domain
    |--------------------------------------------------------------------------
    */

    'domain' => env('HORIZON_DOMAIN'),

    /*
    |--------------------------------------------------------------------------
    | Horizon Path
    |--------------------------------------------------------------------------
    */

    'path' => env('HORIZON_PATH', 'horizon'),

    /*
    |--------------------------------------------------------------------------
    | Horizon Redis Connection
    |--------------------------------------------------------------------------
    |
    | Plan 01 provisioned a dedicated 'horizon' Redis connection (DB index 2)
    | in config/database.php. Horizon metadata (queues, metrics, supervisors)
    | lives on that logical DB, separate from cache (DB 1) and default (DB 0)
    | so FLUSHDB on cache does not wipe queue state.
    |
    */

    'use' => 'horizon',

    /*
    |--------------------------------------------------------------------------
    | Horizon Redis Prefix
    |--------------------------------------------------------------------------
    */

    'prefix' => env(
        'HORIZON_PREFIX',
        Str::slug(env('APP_NAME', 'laravel'), '_').'_horizon:'
    ),

    /*
    |--------------------------------------------------------------------------
    | Horizon Route Middleware
    |--------------------------------------------------------------------------
    */

    'middleware' => ['web'],

    /*
    |--------------------------------------------------------------------------
    | Queue Wait Time Thresholds
    |--------------------------------------------------------------------------
    */

    'waits' => [
        'redis:default' => 60,
        'redis:webhook-inbound' => 30,
        'redis:crm-bitrix' => 120,
        'redis:sync-woo-push' => 90,
        'redis:sync-bulk' => 1800,
        'redis:competitor-csv' => 600,
        'redis:critical' => 30,
    ],

    /*
    |--------------------------------------------------------------------------
    | Job Trimming Times
    |--------------------------------------------------------------------------
    */

    'trim' => [
        'recent' => 60,
        'pending' => 60,
        'completed' => 60,
        'recent_failed' => 10080,
        'failed' => 10080,
        'monitored' => 10080,
    ],

    /*
    |--------------------------------------------------------------------------
    | Silenced Jobs
    |--------------------------------------------------------------------------
    */

    'silenced' => [
        // App\Jobs\ExampleJob::class,
    ],

    'silenced_tags' => [
        // 'notifications',
    ],

    /*
    |--------------------------------------------------------------------------
    | Metrics
    |--------------------------------------------------------------------------
    */

    'metrics' => [
        'trim_snapshots' => [
            'job' => 24,
            'queue' => 24,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Fast Termination
    |--------------------------------------------------------------------------
    */

    'fast_termination' => false,

    /*
    |--------------------------------------------------------------------------
    | Memory Limit (MB)
    |--------------------------------------------------------------------------
    */

    'memory_limit' => 64,

    /*
    |--------------------------------------------------------------------------
    | Queue Worker Configuration
    |--------------------------------------------------------------------------
    |
    | FOUND-09 supervisors — 7 production supervisors mapping to 7 named queues
    | per 01-RESEARCH.md §4. Worker counts respect external API rate limits:
    |   - crm-bitrix-supervisor maxProcesses=2 (Bitrix 2 req/sec hard cap)
    |   - sync-woo-push-supervisor maxProcesses<=3 (Woo 100 req/min headroom)
    |
    | Local dev uses a single all-in-one supervisor covering every queue so
    | `php artisan horizon` runs on a single dev machine without supervisor
    | multiplication.
    |
    */

    'defaults' => [
        'supervisor-1' => [
            'connection' => 'redis',
            'queue' => ['default'],
            'balance' => 'auto',
            'autoScalingStrategy' => 'time',
            'maxProcesses' => 1,
            'maxTime' => 0,
            'maxJobs' => 0,
            'memory' => 128,
            'tries' => 1,
            'timeout' => 60,
            'nice' => 0,
        ],
    ],

    'environments' => [
        'production' => [
            'webhook-inbound-supervisor' => [
                'connection' => 'redis',
                'queue' => ['webhook-inbound'],
                'balance' => 'simple',
                'minProcesses' => 3,
                'maxProcesses' => 10,
                'balanceMaxShift' => 1,
                'balanceCooldown' => 3,
                'tries' => 3,
                'timeout' => 60,
                'memory' => 128,
            ],
            'crm-bitrix-supervisor' => [
                'connection' => 'redis',
                'queue' => ['crm-bitrix'],
                'balance' => 'simple',
                'minProcesses' => 1,
                'maxProcesses' => 2,
                'tries' => 5,
                'timeout' => 120,
                'memory' => 256,
            ],
            'sync-woo-push-supervisor' => [
                'connection' => 'redis',
                'queue' => ['sync-woo-push'],
                'balance' => 'auto',
                'minProcesses' => 2,
                'maxProcesses' => 3,
                'tries' => 5,
                'timeout' => 90,
                'memory' => 256,
            ],
            'sync-bulk-supervisor' => [
                'connection' => 'redis',
                'queue' => ['sync-bulk'],
                'balance' => 'simple',
                'minProcesses' => 1,
                'maxProcesses' => 1,
                'tries' => 2,
                'timeout' => 1800,
                'memory' => 512,
            ],
            'competitor-csv-supervisor' => [
                'connection' => 'redis',
                'queue' => ['competitor-csv'],
                'balance' => 'simple',
                'minProcesses' => 1,
                'maxProcesses' => 2,
                'tries' => 3,
                'timeout' => 600,
                'memory' => 512,
            ],
            'critical-supervisor' => [
                'connection' => 'redis',
                'queue' => ['critical'],
                'balance' => 'simple',
                'minProcesses' => 2,
                'maxProcesses' => 5,
                'tries' => 3,
                'timeout' => 60,
                'memory' => 128,
            ],
            'default-supervisor' => [
                'connection' => 'redis',
                'queue' => ['default'],
                'balance' => 'auto',
                'minProcesses' => 1,
                'maxProcesses' => 3,
                'tries' => 3,
                'timeout' => 120,
                'memory' => 256,
            ],
        ],

        'local' => [
            'all-in-one' => [
                'connection' => 'redis',
                'queue' => ['critical', 'webhook-inbound', 'crm-bitrix', 'sync-woo-push', 'sync-bulk', 'competitor-csv', 'default'],
                'balance' => 'auto',
                'minProcesses' => 1,
                'maxProcesses' => 3,
                'tries' => 1,
                'timeout' => 300,
                'memory' => 128,
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | File Watcher Configuration
    |--------------------------------------------------------------------------
    */

    'watch' => [
        'app',
        'bootstrap',
        'config/**/*.php',
        'database/**/*.php',
        'public/**/*.php',
        'resources/**/*.php',
        'routes',
        'composer.lock',
        'composer.json',
        '.env',
    ],
];
