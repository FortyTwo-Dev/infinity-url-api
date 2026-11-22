<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Destination inspection
    |--------------------------------------------------------------------------
    |
    | The check runs once, at link creation, and never on a click. It is
    | disabled in the end-to-end run so the journey stays deterministic: a
    | destination must never depend on the network of the CI runner.
    |
    */

    'enabled' => (bool) env('ABUSE_CHECK_ENABLED', true),

    'psl_path' => env('PSL_PATH', resource_path('psl/public_suffix_list.dat')),

    /*
    |--------------------------------------------------------------------------
    | Local dumps
    |--------------------------------------------------------------------------
    |
    | Blocklists are downloaded once a day, in full, then queried locally.
    | No client URL ever leaves the server (D-96). URLhaus asks for a free
    | Auth-Key; the other two are public feeds.
    |
    */

    'dump_path' => env('ABUSE_DUMP_PATH', storage_path('app/abuse')),

    'sources' => [
        'urlhaus' => [
            'url' => env('URLHAUS_URL', 'https://urlhaus.abuse.ch/downloads/text/'),
            'auth_key' => env('URLHAUS_AUTH_KEY'),
        ],
        'phishing_army' => [
            'url' => env('PHISHING_ARMY_URL', 'https://phishing.army/download/phishing_army_blocklist_extended.txt'),
            'auth_key' => null,
        ],
        'openphish' => [
            'url' => env('OPENPHISH_URL', 'https://openphish.com/feed.txt'),
            'auth_key' => null,
        ],
    ],

    'blocklist' => [
        // Entries absent from every dump for longer than this are purged.
        'retention_days' => 30,
    ],

    /*
    |--------------------------------------------------------------------------
    | Probes
    |--------------------------------------------------------------------------
    |
    | Seconds. A timeout fails open: the absence of proof of safety is not a
    | proof of danger (D-126).
    |
    */

    'timeouts' => [
        'http' => (int) env('ABUSE_HTTP_TIMEOUT', 3),
        'tls' => (int) env('ABUSE_TLS_TIMEOUT', 3),
        'rdap' => (int) env('ABUSE_RDAP_TIMEOUT', 3),
    ],

    'http' => [
        'user_agent' => 'InfinityURL-AbuseCheck/1.0',
        'max_redirects' => 3,
        // Partial GET: enough for headers, never the whole body.
        'range_bytes' => 65_536,
    ],

    /*
    |--------------------------------------------------------------------------
    | RDAP
    |--------------------------------------------------------------------------
    |
    | Domain age is the generalisable signal: a young domain serving a login
    | form is suspicious whatever brand it imitates. Only the registrable
    | domain is sent, never the full URL, and the answer is cached: one lookup
    | per domain and per week, not per link.
    |
    */

    'rdap' => [
        'url' => env('RDAP_URL', 'https://rdap.org/domain/'),
        'cache_days' => 7,
    ],

    /*
    |--------------------------------------------------------------------------
    | Review thresholds
    |--------------------------------------------------------------------------
    |
    | A typosquat alone never blocks: it goes to manual review. Only the
    | combination with a second signal blocks (docs/04 §3.5).
    |
    */

    'young_domain_days' => 30,
    'max_subdomains' => 3,
    'sensitive_paths' => ['login', 'signin', 'verify', 'secure', 'account', 'password'],

    /*
    |--------------------------------------------------------------------------
    | Alerts
    |--------------------------------------------------------------------------
    |
    | ntfy is self-hosted. An empty URL disables the alert, never the work.
    |
    */

    'ntfy_url' => env('NTFY_URL'),

    /*
    |--------------------------------------------------------------------------
    | Retention
    |--------------------------------------------------------------------------
    */

    'reports' => [
        'retention_months' => 12,
        'rejected_days' => 90,
        'rate_limit_per_hour' => 5,
    ],

    /*
    |--------------------------------------------------------------------------
    | Brand discovery
    |--------------------------------------------------------------------------
    |
    | A token seen only once is noise; a token imitated twice in a week is a
    | candidate. A human still decides.
    |
    */

    'proposals' => [
        'min_occurrences' => 2,
    ],

];
