<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Support\ProblemResponse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Exceptions\MissingAbilityException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * Maps every exception under /v1 to an RFC 9457 problem document.
 */
final class ProblemRenderer
{
    public function render(Throwable $exception, Request $request): ?JsonResponse
    {
        if (! $request->is('v1') && ! $request->is('v1/*')) {
            return null;
        }

        return match (true) {
            $exception instanceof ProblemException => $this->problem($exception, $request),
            $exception instanceof ValidationException => $this->validation($exception, $request),
            $exception instanceof MissingAbilityException => $this->scopeMissing($request),
            $exception instanceof AccessDeniedHttpException => $this->accessDenied($exception, $request),
            $exception instanceof AuthenticationException => $this->unauthorized($request),
            $exception instanceof AuthorizationException => $this->forbidden($request),
            $exception instanceof ModelNotFoundException,
            $exception instanceof NotFoundHttpException => $this->notFound($request),
            $exception instanceof MethodNotAllowedHttpException => $this->methodNotAllowed($request),
            $exception instanceof ThrottleRequestsException => $this->throttled($exception, $request),
            default => $this->serverError($exception, $request),
        };
    }

    private function problem(ProblemException $exception, Request $request): JsonResponse
    {
        return ProblemResponse::make(
            $request,
            $exception->status,
            $exception->problemCode,
            $exception->title,
            $exception->getMessage(),
            $exception->errors,
        );
    }

    private function validation(ValidationException $exception, Request $request): JsonResponse
    {
        return ProblemResponse::make(
            $request,
            422,
            'validation_failed',
            'Validation failed',
            'The request is invalid.',
            $exception->errors(),
        );
    }

    private function scopeMissing(Request $request): JsonResponse
    {
        return ProblemResponse::make(
            $request,
            403,
            'scope_missing',
            'Scope missing',
            'The token does not have the required ability.',
        );
    }

    private function accessDenied(AccessDeniedHttpException $exception, Request $request): JsonResponse
    {
        if ($exception->getPrevious() instanceof MissingAbilityException) {
            return $this->scopeMissing($request);
        }

        return $this->forbidden($request);
    }

    private function unauthorized(Request $request): JsonResponse
    {
        return ProblemResponse::make(
            $request,
            401,
            'unauthorized',
            'Unauthorized',
            'Authentication is required.',
        );
    }

    private function forbidden(Request $request): JsonResponse
    {
        return ProblemResponse::make(
            $request,
            403,
            'forbidden',
            'Forbidden',
            'This action is not allowed.',
        );
    }

    private function notFound(Request $request): JsonResponse
    {
        return ProblemResponse::make(
            $request,
            404,
            'not_found',
            'Not found',
            'The requested resource does not exist.',
        );
    }

    private function methodNotAllowed(Request $request): JsonResponse
    {
        return ProblemResponse::make(
            $request,
            405,
            'method_not_allowed',
            'Method not allowed',
            'This method is not supported for this resource.',
        );
    }

    private function throttled(ThrottleRequestsException $exception, Request $request): JsonResponse
    {
        return ProblemResponse::make(
            $request,
            429,
            'rate_limited',
            'Too many requests',
            'Too many requests. Retry later.',
        )->withHeaders($exception->getHeaders());
    }

    private function serverError(Throwable $exception, Request $request): JsonResponse
    {
        report($exception);

        return ProblemResponse::make(
            $request,
            500,
            'server_error',
            'Server error',
            'An unexpected error occurred.',
        );
    }
}
