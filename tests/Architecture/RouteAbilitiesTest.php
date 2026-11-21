<?php

use Illuminate\Support\Facades\Route;

/**
 * Middleware identity, without its parameters: "auth:sanctum" -> "auth",
 * "abilities:links:read" -> "abilities".
 */
function middlewareName(mixed $middleware): ?string
{
    if (! is_string($middleware)) {
        return null;
    }

    return strtolower(trim(explode(':', $middleware, 2)[0]));
}

function isAuthenticationMiddleware(mixed $middleware): bool
{
    return in_array(middlewareName($middleware), [
        'auth',
        'illuminate\auth\middleware\authenticate',
    ], true);
}

function isAbilityMiddleware(mixed $middleware): bool
{
    return in_array(middlewareName($middleware), [
        'ability',
        'abilities',
        'laravel\sanctum\http\middleware\checkabilities',
        'laravel\sanctum\http\middleware\checkforanyability',
    ], true);
}

/**
 * Routes owned by this application: the public api namespace, or an action
 * pointing at a controller of the App namespace. Vendor routes (Horizon,
 * Scramble, Boost, Sanctum, storage, health) are out of scope.
 */
function isApplicationRoute(Illuminate\Routing\Route $route): bool
{
    return str_starts_with($route->uri(), 'api/')
        || str_starts_with($route->getActionName(), 'App\\');
}

it('requires an ability on every authenticated application route', function () {
    $offenders = [];

    foreach (Route::getRoutes() as $route) {
        if (! isApplicationRoute($route)) {
            continue;
        }

        $middleware = $route->gatherMiddleware();

        $authenticated = collect($middleware)->contains(
            static fn (mixed $m): bool => isAuthenticationMiddleware($m)
        );

        if (! $authenticated) {
            continue;
        }

        $hasAbility = collect($middleware)->contains(
            static fn (mixed $m): bool => isAbilityMiddleware($m)
        );

        if (! $hasAbility) {
            $offenders[] = implode('|', $route->methods()).' /'.$route->uri();
        }
    }

    expect($offenders)->toBe(
        [],
        'Authenticated routes must declare an abilities middleware, missing on: '.implode(', ', $offenders)
    );
});

it('exposes no api/user route', function () {
    $uris = collect(Route::getRoutes())
        ->map(static fn (Illuminate\Routing\Route $route): string => $route->uri())
        ->all();

    expect(in_array('api/user', $uris, true))->toBeFalse(
        'The dashboard is a public API client: api/user must not exist.'
    );
});
