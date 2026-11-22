<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Link host
    |--------------------------------------------------------------------------
    |
    | The only host that serves redirects and the static error pages. Every
    | other path on it is rejected before routing. Any other host gets the
    | normal application (API, docs, Horizon).
    |
    */

    'host' => env('LINK_HOST', 'iurl.fr'),

    /*
    |--------------------------------------------------------------------------
    | Public short URL base
    |--------------------------------------------------------------------------
    |
    | Optional. When set, the API builds `short_url` from this base instead of
    | `https://{host}`. It exists for local development, where the API runs on
    | a port and a local hostname: `http://iurl.test:8001`. Production leaves
    | it empty and keeps the canonical HTTPS domain.
    |
    */

    'url' => env('LINK_URL'),

    /*
    |--------------------------------------------------------------------------
    | Cache lifetimes
    |--------------------------------------------------------------------------
    |
    | Seconds. A successful redirect is capped by the link's expires_at, so a
    | 302 is never served from cache after the link has expired.
    |
    */

    'ttl' => [
        'redirect' => 86_400,
        'not_found' => 60,
        'blocked' => 60,
        'expired' => 60,
        'deleted' => 86_400,
    ],

    /*
    |--------------------------------------------------------------------------
    | Singleflight
    |--------------------------------------------------------------------------
    |
    | The lock covers the database lookup of a cold key. A waiter that cannot
    | acquire it within `wait` seconds reads the database directly: redirects
    | fail open.
    |
    */

    'lock' => [
        'seconds' => 10,
        'wait' => 5,
    ],

];
