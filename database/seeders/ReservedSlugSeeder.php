<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ReservedSlugSeeder extends Seeder
{
    /**
     * The list from docs/04, completed with every static first segment of the
     * application routes. A slug here can never be generated or claimed.
     *
     * @var list<string>
     */
    public const SLUGS = [
        'api', 'app', 'admin', 'administrator', 'login', 'logout', 'signup',
        'register', 'auth', 'account', 'dashboard', 'settings', 'billing',
        'invoices', 'stats', 'analytics', 'links', 'domains', 'qr', 'p', 's',
        'static', 'assets', 'images', 'img', 'css', 'js', 'fonts', 'health',
        'healthz', 'ready', 'robots.txt', 'sitemap.xml', 'favicon.ico',
        'manifest.json', '.well-known', 'abuse', 'report', 'legal', 'privacy',
        'terms', 'dpa', 'status', 'docs', 'blog', 'help', 'support', 'about',
        'pricing', 'contact', 'security', 'source', 'license',
        'v1', 'up', 'horizon', 'sanctum', 'storage', '_scramble', '_boost',
        'openapi.json', 'me', 'tokens', 'workspaces',
    ];

    public function run(): void
    {
        $rows = array_map(
            static fn (string $slug): array => ['slug' => $slug, 'reason' => 'reserved'],
            self::SLUGS,
        );

        foreach (array_chunk($rows, 100) as $chunk) {
            DB::table('reserved_slugs')->insertOrIgnore($chunk);
        }
    }
}
