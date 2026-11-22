<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\Analytics\AnalyticsSalt;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Slug enumeration limit, on the cold path only.
 *
 * A successful redirect never pays it: the middleware inspects the response
 * and only spends budget on a 404. The key is an HMAC of the visitor IP with
 * the rotating analytics salt, never the IP itself, and it lives one minute.
 * Traefik keeps the global per-IP ceiling; a Valkey failure serves the 404.
 */
final class LimitLinkNotFound
{
    public function __construct(private readonly AnalyticsSalt $salt) {}

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        return $response->getStatusCode() === 404
            ? $this->limited($request, $response)
            : $response;
    }

    private function limited(Request $request, Response $response): Response
    {
        $salt = $this->salt->current();

        // Without the salt there is no fingerprint contract: fail open rather
        // than hash under a permanent secret such as APP_KEY.
        if ($salt === null) {
            return $response;
        }

        try {
            $key = 'link404:'.hash_hmac('sha256', (string) $request->ip(), $salt);

            if (RateLimiter::tooManyAttempts($key, $this->limit())) {
                return $this->tooMany(max(1, RateLimiter::availableIn($key)));
            }

            RateLimiter::hit($key, $this->decaySeconds());
        } catch (Throwable $exception) {
            report($exception);
        }

        return $response;
    }

    private function limit(): int
    {
        return (int) config('analytics.not_found.limit', 60);
    }

    private function decaySeconds(): int
    {
        return (int) config('analytics.not_found.decay_seconds', 60);
    }

    private function tooMany(int $retryAfter): Response
    {
        return response('', 429, [
            'Retry-After' => (string) $retryAfter,
            'Cache-Control' => 'no-store',
            'Referrer-Policy' => 'no-referrer',
            'X-Robots-Tag' => 'noindex',
        ]);
    }
}
