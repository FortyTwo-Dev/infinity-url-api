<?php

use Illuminate\Support\Facades\Route;

it('ships a non empty valid openapi 3.1 document', function () {
    $path = base_path('openapi.json');

    expect(file_exists($path))->toBeTrue();

    $raw = (string) file_get_contents($path);
    expect($raw)->not->toBe('');

    $document = json_decode($raw, true);
    expect(json_last_error())->toBe(JSON_ERROR_NONE);
    expect($document)->toBeArray();

    expect($document['openapi'])->toBeString()->toStartWith('3.1');
    expect($document['paths'] ?? [])->not->toBeEmpty();

    $schemas = $document['components']['schemas'] ?? [];
    expect($schemas)->toBeArray()->not->toBeEmpty();
    expect(array_keys($schemas))->not->toContain('unknown');
});

it('documents every v1 application route with its lowercase method', function () {
    $document = json_decode((string) file_get_contents(base_path('openapi.json')), true);
    $paths = $document['paths'] ?? [];

    $routes = collect(Route::getRoutes()->getRoutes())->filter(function ($route): bool {
        return str_starts_with(ltrim($route->uri(), '/'), 'v1/')
            && str_starts_with((string) $route->getActionName(), 'App\\');
    });

    expect($routes)->not->toBeEmpty();

    $missing = [];

    foreach ($routes as $route) {
        $path = '/'.substr(ltrim($route->uri(), '/'), 3);

        foreach ($route->methods() as $method) {
            if (strtoupper($method) === 'HEAD') {
                continue;
            }

            $method = strtolower($method);

            if (! isset($paths[$path][$method])) {
                $missing[] = strtoupper($method).' '.$path;
            }
        }
    }

    expect($missing)->toBe([]);
});

it('declares security for authenticated operations and none for public ones', function () {
    $document = json_decode((string) file_get_contents(base_path('openapi.json')), true);
    $paths = $document['paths'] ?? [];
    $documentSecurity = $document['security'] ?? [];

    $publicPaths = [
        '/auth/register',
        '/auth/verify-email',
        '/auth/verification/resend',
        '/auth/login',
        '/auth/2fa/verify',
        '/auth/refresh',
        '/auth/logout',
        '/auth/password/forgot',
        '/auth/password/reset',
    ];

    $routes = collect(Route::getRoutes()->getRoutes())->filter(function ($route): bool {
        return str_starts_with(ltrim($route->uri(), '/'), 'v1/')
            && str_starts_with((string) $route->getActionName(), 'App\\');
    });

    foreach ($routes as $route) {
        $path = '/'.substr(ltrim($route->uri(), '/'), 3);

        foreach ($route->methods() as $method) {
            if (strtoupper($method) === 'HEAD') {
                continue;
            }

            $method = strtolower($method);
            $operation = $paths[$path][$method] ?? [];
            $isPublic = in_array($path, $publicPaths, true);

            if ($isPublic) {
                expect($operation['security'] ?? [])->toBe([]);

                if ($documentSecurity !== []) {
                    expect($operation)->toHaveKey('security');
                }

                continue;
            }

            expect($operation['security'] ?? $documentSecurity)->not->toBeEmpty();
        }
    }
});

it('declares a bearer security scheme', function () {
    $document = json_decode((string) file_get_contents(base_path('openapi.json')), true);
    $schemes = $document['components']['securitySchemes'] ?? [];

    expect($schemes)->toBeArray()->not->toBeEmpty();

    $bearer = collect($schemes)->contains(function ($scheme): bool {
        return is_array($scheme)
            && strtolower((string) ($scheme['type'] ?? '')) === 'http'
            && strtolower((string) ($scheme['scheme'] ?? '')) === 'bearer';
    });

    expect($bearer)->toBeTrue();
});
