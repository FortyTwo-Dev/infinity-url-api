<?php

declare(strict_types=1);

use App\Exceptions\ProblemRenderer;
use App\Http\Middleware\RateLimitHeaders;
use App\Http\Middleware\RestrictLinkHost;
use App\Http\Middleware\ThrottleV1;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;
use Laravel\Sanctum\Http\Middleware\CheckForAnyAbility;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'v1',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            require base_path('routes/redirect.php');
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prepend([
            RateLimitHeaders::class,
            RestrictLinkHost::class,
            ThrottleV1::class,
        ]);
        $middleware->alias([
            'abilities' => CheckAbilities::class,
            'ability' => CheckForAnyAbility::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('v1') || $request->is('v1/*') || $request->expectsJson(),
        );

        $exceptions->render(fn (Throwable $exception, Request $request) => app(ProblemRenderer::class)->render($exception, $request));
    })->create();
