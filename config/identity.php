<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Email blind index
    |--------------------------------------------------------------------------
    |
    | HMAC key dedicated to `users.email_hash`. It must never be the same as
    | `APP_KEY`, so rotating the application key does not invalidate email
    | uniqueness. It never rotates. See D-110.
    |
    */

    'blind_index_key' => env('BLIND_INDEX_KEY'),

    /*
    |--------------------------------------------------------------------------
    | Dashboard
    |--------------------------------------------------------------------------
    |
    | Base URL of the dashboard, used in transactional email links. It is never
    | the link domain.
    |
    */

    'dashboard_url' => env('DASHBOARD_URL', 'https://url.infinityproject.fr'),

    /*
    |--------------------------------------------------------------------------
    | Problem details
    |--------------------------------------------------------------------------
    |
    | Base URI of the RFC 9457 problem types.
    |
    */

    'problem_base' => env('PROBLEM_BASE', 'https://api.url.infinityproject.fr/problems'),

    /*
    |--------------------------------------------------------------------------
    | Plan limits
    |--------------------------------------------------------------------------
    |
    | The free plan of the hosted instance. `custom_domains` stays at zero until
    | v0.2 (D-127).
    |
    */

    'limits' => [
        'active_links' => 5,
        'custom_domains' => 0,
        'stats_retention_days' => (int) env('STATS_RETENTION_DAYS', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | Session tokens
    |--------------------------------------------------------------------------
    |
    | The dashboard access token lives in memory for fifteen minutes. The
    | refresh token rotates on every use and lives thirty days.
    |
    */

    'tokens' => [
        'access_minutes' => 15,
        'refresh_days' => 30,
        'recovery_codes' => 10,
    ],

    /*
    |--------------------------------------------------------------------------
    | One-time links
    |--------------------------------------------------------------------------
    */

    'verification_hours' => 24,
    'reset_minutes' => 60,

    'totp' => [
        'challenge_minutes' => 5,
        'window' => 1,
    ],

    /*
    |--------------------------------------------------------------------------
    | Capabilities
    |--------------------------------------------------------------------------
    |
    | Features available to the account and its plan, never the scopes of the
    | current token. The dashboard hides UI when a capability is missing.
    |
    */

    'capabilities' => [
        'links:read',
        'links:write',
        'stats:read',
        'tokens:read',
        'tokens:write',
        'qr',
    ],

    /*
    |--------------------------------------------------------------------------
    | API scopes
    |--------------------------------------------------------------------------
    |
    | Scopes an API token may receive. `me:write` and `*` are dashboard-only.
    |
    */

    'api_scopes' => [
        'me:read',
        'links:read',
        'links:write',
        'stats:read',
        'tokens:read',
        'tokens:write',
    ],

];
