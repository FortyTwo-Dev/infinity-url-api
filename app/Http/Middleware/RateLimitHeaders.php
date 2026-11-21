<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class RateLimitHeaders
{
    private const HEADERS = [
        'X-RateLimit-Limit' => 'RateLimit-Limit',
        'X-RateLimit-Remaining' => 'RateLimit-Remaining',
        'X-RateLimit-Reset' => 'RateLimit-Reset',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $hasLimit = $response->headers->has('X-RateLimit-Limit');

        foreach (self::HEADERS as $legacy => $ietf) {
            $value = $response->headers->get($legacy);

            if ($value === null) {
                if ($ietf === 'RateLimit-Reset' && $hasLimit) {
                    $response->headers->set($ietf, (string) $this->windowFor($request));
                }

                continue;
            }

            if ($ietf === 'RateLimit-Reset') {
                $value = (string) max(0, (int) $value - now()->getTimestamp());
            }

            $response->headers->set($ietf, $value);
            $response->headers->remove($legacy);
        }

        return $response;
    }

    private function windowFor(Request $request): int
    {
        $middleware = array_reverse($request->route()?->gatherMiddleware() ?? []);

        foreach ($middleware as $entry) {
            if (! is_string($entry) || ! str_starts_with($entry, 'throttle:')) {
                continue;
            }

            return match (substr($entry, strlen('throttle:'))) {
                'token-create', 'verification-resend', 'password-forgot' => 3600,
                'login', 'totp' => 900,
                'register' => 86_400,
                default => 60,
            };
        }

        return 60;
    }
}
