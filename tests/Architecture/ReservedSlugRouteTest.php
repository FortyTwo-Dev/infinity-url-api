<?php

use Database\Seeders\ReservedSlugSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

/**
 * @param  array<int, string>  $uris
 * @param  array<int, string>  $reserved
 * @return array<int, string>
 */
function reservedSlugRouteOffenders(array $uris, array $reserved): array
{
    $offenders = [];

    foreach ($uris as $uri) {
        $segment = explode('/', trim($uri, '/'))[0];

        if ($segment === '' || str_starts_with($segment, '{')) {
            continue;
        }

        if (! in_array($segment, $reserved, true)) {
            $offenders[$segment] = true;
        }
    }

    $offenders = array_keys($offenders);
    sort($offenders);

    return $offenders;
}

it('reserves the first static segment of every route', function () {
    $this->seed(ReservedSlugSeeder::class);

    $reserved = DB::table('reserved_slugs')->pluck('slug')->all();

    foreach (['v1', 'up', 'docs', 'openapi.json', 'horizon', 'sanctum', 'storage', '_scramble', '_boost'] as $required) {
        expect($reserved)->toContain($required);
    }

    $uris = collect(Route::getRoutes())
        ->map(fn ($route): string => $route->uri())
        ->all();

    expect(reservedSlugRouteOffenders($uris, $reserved))->toBe([]);
});

it('detects a route whose first segment is not reserved', function () {
    $this->seed(ReservedSlugSeeder::class);

    $reserved = DB::table('reserved_slugs')->pluck('slug')->all();

    $offenders = reservedSlugRouteOffenders(
        ['v1/links', 'not-reserved-xyz/{id}', '{locale}/home'],
        $reserved,
    );

    expect($offenders)->toBe(['not-reserved-xyz']);
});
