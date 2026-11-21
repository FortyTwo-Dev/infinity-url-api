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
