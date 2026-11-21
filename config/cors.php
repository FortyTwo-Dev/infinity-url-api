<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure your settings for cross-origin resource sharing
    | or "CORS". This determines what cross-origin operations may execute
    | in web browsers. You are free to adjust these settings as needed.
    |
    | To learn more: https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS
    |
    */

    'paths' => ['v1/*', 'docs', 'docs/api', 'openapi.json'],

    'allowed_methods' => ['GET', 'HEAD', 'POST', 'PATCH', 'DELETE', 'OPTIONS'],

    'allowed_origins' => [env('DASHBOARD_URL', 'https://url.infinityproject.fr')],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['Authorization', 'Content-Type'],

    'exposed_headers' => [
        'RateLimit-Limit',
        'RateLimit-Remaining',
        'RateLimit-Reset',
        'Retry-After',
    ],

    'max_age' => 0,

    'supports_credentials' => true,

];
