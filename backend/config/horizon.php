<?php

// config/horizon.php

use Illuminate\Support\Str;

return [

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
    */
    'use' => 'default',

    /*
    |--------------------------------------------------------------------------
    | Horizon Redis Prefix
    |--------------------------------------------------------------------------
    */
    'prefix' => env('HORIZON_PREFIX', Str::slug(env('APP_NAME', 'waapi'), '_') . '_horizon:'),

    /*
    |--------------------------------------------------------------------------
    | Horizon Route Middleware
    |--------------------------------------------------------------------------
    */
    'middleware' => ['web'],

    /*
    |--------------------------------------------------------------------------
    | Queue Wait Time Thresholds (seconds)
    |--------------------------------------------------------------------------
    */
    'waits' => [
        'redis:campaigns' => 120,
        'redis:webhooks'  => 10,
        'redis:default'   => 60,
    ],

    /*
    |--------------------------------------------------------------------------
    | Job Trimming Times (minutes)
    |--------------------------------------------------------------------------
    */
    'trim' => [
        'recent'        => 60,
        'pending'       => 60,
        'completed'     => 60,
        'recent_failed' => 10080,   // 7 days
        'failed'        => 10080,
        'monitored'     => 10080,
    ],

    /*
    |--------------------------------------------------------------------------
    | Silenced Jobs
    |--------------------------------------------------------------------------
    */
    'silenced' => [],

    /*
    |--------------------------------------------------------------------------
    | Metrics Snapshot Retention (hours)
    |--------------------------------------------------------------------------
    */
    'metrics' => [
        'trim_snapshots' => [
            'job'   => 24,
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
    'memory_limit' => 128,

    /*
    |--------------------------------------------------------------------------
    | Queue Worker Configuration
    |--------------------------------------------------------------------------
    |
    | campaigns  → ProcessCampaignBatch: high concurrency, long timeout (300s)
    | webhooks   → WebhookController jobs: very high concurrency, short timeout (30s)
    | default    → All other async jobs
    |
    */
    'defaults' => [
        'supervisor-campaigns' => [
            'connection'    => 'redis',
            'queue'         => ['campaigns'],
            'balance'       => 'simple',
            'autoScalingStrategy' => 'time',
            'minProcesses'  => 1,
            'maxProcesses'  => 10,
            'multiplexing'  => ['enabled' => false, 'boost' => 1.0],
            'tries'         => 3,
            'nice'          => 0,
            'timeout'       => 300,
        ],

        'supervisor-webhooks' => [
            'connection'    => 'redis',
            'queue'         => ['webhooks'],
            'balance'       => 'simple',
            'autoScalingStrategy' => 'time',
            'minProcesses'  => 2,
            'maxProcesses'  => 20,
            'multiplexing'  => ['enabled' => false, 'boost' => 1.0],
            'tries'         => 3,
            'nice'          => 0,
            'timeout'       => 30,
        ],

        'supervisor-default' => [
            'connection'    => 'redis',
            'queue'         => ['default'],
            'balance'       => 'auto',
            'autoScalingStrategy' => 'time',
            'minProcesses'  => 1,
            'maxProcesses'  => 5,
            'multiplexing'  => ['enabled' => false, 'boost' => 1.0],
            'tries'         => 3,
            'nice'          => 0,
            'timeout'       => 90,
        ],
    ],

    'environments' => [

        // ── Production ────────────────────────────────────────────────────────
        'production' => [
            'supervisor-campaigns' => [
                'connection'   => 'redis',
                'queue'        => ['campaigns'],
                'balance'      => 'simple',
                'minProcesses' => 2,
                'maxProcesses' => 10,
                'tries'        => 3,
                'timeout'      => 300,
            ],
            'supervisor-webhooks' => [
                'connection'   => 'redis',
                'queue'        => ['webhooks'],
                'balance'      => 'simple',
                'minProcesses' => 4,
                'maxProcesses' => 20,
                'tries'        => 3,
                'timeout'      => 30,
            ],
            'supervisor-default' => [
                'connection'   => 'redis',
                'queue'        => ['default'],
                'balance'      => 'auto',
                'minProcesses' => 1,
                'maxProcesses' => 5,
                'tries'        => 3,
                'timeout'      => 90,
            ],
        ],

        // ── Staging ───────────────────────────────────────────────────────────
        'staging' => [
            'supervisor-1' => [
                'connection'   => 'redis',
                'queue'        => ['default', 'campaigns', 'webhooks'],
                'balance'      => 'simple',
                'minProcesses' => 1,
                'maxProcesses' => 5,
                'tries'        => 3,
                'timeout'      => 300,
            ],
        ],

        // ── Local development ─────────────────────────────────────────────────
        'local' => [
            'supervisor-1' => [
                'connection'   => 'redis',
                'queue'        => ['default', 'campaigns', 'webhooks'],
                'balance'      => 'simple',
                'minProcesses' => 1,
                'maxProcesses' => 3,
                'tries'        => 3,
                'timeout'      => 300,
            ],
        ],
    ],
];
