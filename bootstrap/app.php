<?php

declare(strict_types=1);

use App\Exceptions\ProblemException;
use App\Http\Middleware\RateLimitHeaders;
use App\Http\Middleware\RestrictLinkHost;
use App\Http\Middleware\ThrottleV1;
use App\Support\ProblemResponse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Exceptions\MissingAbilityException;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;
use Laravel\Sanctum\Http\Middleware\CheckForAnyAbility;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

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

        $exceptions->render(function (Throwable $exception, Request $request) {
            if (! $request->is('v1') && ! $request->is('v1/*')) {
                return null;
            }

            if ($exception instanceof ProblemException) {
                return ProblemResponse::make(
                    $request,
                    $exception->status,
                    $exception->problemCode,
                    $exception->title,
                    $exception->getMessage(),
                    $exception->errors,
                );
            }

            if ($exception instanceof ValidationException) {
                return ProblemResponse::make(
                    $request,
                    422,
                    'validation_failed',
                    'Validation failed',
                    'The request is invalid.',
                    $exception->errors(),
                );
            }

            if ($exception instanceof MissingAbilityException) {
                return ProblemResponse::make(
                    $request,
                    403,
                    'scope_missing',
                    'Scope missing',
                    'The token does not have the required ability.',
                );
            }

            if ($exception instanceof AccessDeniedHttpException) {
                $previous = $exception->getPrevious();

                return $previous instanceof MissingAbilityException
                    ? ProblemResponse::make(
                        $request,
                        403,
                        'scope_missing',
                        'Scope missing',
                        'The token does not have the required ability.',
                    )
                    : ProblemResponse::make(
                        $request,
                        403,
                        'forbidden',
                        'Forbidden',
                        'This action is not allowed.',
                    );
            }

            if ($exception instanceof AuthenticationException) {
                return ProblemResponse::make(
                    $request,
                    401,
                    'unauthorized',
                    'Unauthorized',
                    'Authentication is required.',
                );
            }

            if ($exception instanceof AuthorizationException) {
                return ProblemResponse::make(
                    $request,
                    403,
                    'forbidden',
                    'Forbidden',
                    'This action is not allowed.',
                );
            }

            if ($exception instanceof ModelNotFoundException || $exception instanceof NotFoundHttpException) {
                return ProblemResponse::make(
                    $request,
                    404,
                    'not_found',
                    'Not found',
                    'The requested resource does not exist.',
                );
            }

            if ($exception instanceof MethodNotAllowedHttpException) {
                return ProblemResponse::make(
                    $request,
                    405,
                    'method_not_allowed',
                    'Method not allowed',
                    'This method is not supported for this resource.',
                );
            }

            if ($exception instanceof ThrottleRequestsException) {
                return ProblemResponse::make(
                    $request,
                    429,
                    'rate_limited',
                    'Too many requests',
                    'Too many requests. Retry later.',
                )->withHeaders($exception->getHeaders());
            }

            report($exception);

            return ProblemResponse::make(
                $request,
                500,
                'server_error',
                'Server error',
                'An unexpected error occurred.',
            );
        });
    })->create();
