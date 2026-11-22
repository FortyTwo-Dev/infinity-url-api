<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Analytics salt
    |--------------------------------------------------------------------------
    |
    | The HMAC salt lives in a file only: never in the database, never in a
    | backup, never in the repository. It rotates every Monday at 00:00 UTC and
    | the previous value is destroyed. See docs/11-pipeline.md §7.
    |
    */

    'salt_path' => env('ANALYTICS_SALT_PATH', '/secrets/analytics.salt'),

    /*
    |--------------------------------------------------------------------------
    | Retention
    |--------------------------------------------------------------------------
    |
    | Calendar days of aggregated counters kept on the hosted instance. The
    | same variable drives the plan limit exposed by GET /me.
    |
    */

    'retention_days' => (int) env('STATS_RETENTION_DAYS', 30),

    /*
    |--------------------------------------------------------------------------
    | Queue
    |--------------------------------------------------------------------------
    |
    | Click jobs have their own queue so a backlog never delays another kind of
    | work. Horizon listens on both `default` and `analytics`.
    |
    */

    'queue' => 'analytics',

    /*
    |--------------------------------------------------------------------------
    | Idempotence
    |--------------------------------------------------------------------------
    |
    | How long the per-job guard survives. Long enough to cover every retry,
    | short enough to stay cheap.
    |
    */

    'job_ttl_hours' => 6,

    /*
    |--------------------------------------------------------------------------
    | Dedup grace
    |--------------------------------------------------------------------------
    |
    | A click made just before midnight can be processed just after it. Both
    | dedup keys survive one extra hour, and a bucket is closed past that
    | grace: a late job then counts its click but never revives a unique that
    | may already have been counted.
    |
    */

    'dedup_grace_minutes' => 60,

    /*
    |--------------------------------------------------------------------------
    | Reporting window
    |--------------------------------------------------------------------------
    |
    | Default length of a statistics query and the hard cap on the number of
    | buckets in a series.
    |
    */

    'default_window_days' => 30,

    'max_buckets' => 366,

    /*
    |--------------------------------------------------------------------------
    | Enumeration limit
    |--------------------------------------------------------------------------
    |
    | 404s are limited per fingerprinted IP on the cold path. A successful
    | redirect never pays this budget. Traefik keeps the global per-IP ceiling.
    |
    */

    'not_found' => [
        'limit' => 60,
        'decay_seconds' => 60,
    ],

];
