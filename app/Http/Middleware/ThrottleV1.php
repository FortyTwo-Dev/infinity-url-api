<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\PersonalAccessToken;
use App\Models\User;
use Closure;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * General API limit: 120 requests per minute, per token when the bearer is
 * valid, per IP otherwise. It runs before authentication and before routing,
 * so public routes, auth failures and unknown /v1 paths also carry the
 * RateLimit-* headers.
 */
final class ThrottleV1
{
    public const LIMIT = 120;

    public const DECAY_SECONDS = 60;

    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->is('v1') && ! $request->is('v1/*')) {
            return $next($request);
        }

        $key = $this->key($request);

        if (RateLimiter::tooManyAttempts($key, self::LIMIT)) {
            $retryAfter = max(1, RateLimiter::availableIn($key));

            throw new ThrottleRequestsException('Too Many Attempts.', null, [
                'X-RateLimit-Limit' => self::LIMIT,
                'X-RateLimit-Remaining' => 0,
                'X-RateLimit-Reset' => now()->addSeconds($retryAfter)->getTimestamp(),
                'Retry-After' => $retryAfter,
            ]);
        }

        RateLimiter::hit($key, self::DECAY_SECONDS);

        $response = $next($request);

        if (! $response->headers->has('X-RateLimit-Limit')) {
            $response->headers->add([
                'X-RateLimit-Limit' => self::LIMIT,
                'X-RateLimit-Remaining' => RateLimiter::retriesLeft($key, self::LIMIT),
                'X-RateLimit-Reset' => now()->addSeconds(self::DECAY_SECONDS)->getTimestamp(),
            ]);
        }

        return $response;
    }

    private function key(Request $request): string
    {
        $user = Auth::guard('sanctum')->user();
        $token = $user instanceof User ? $user->currentAccessToken() : null;

        if ($token instanceof PersonalAccessToken) {
            return 'token:'.$token->id;
        }

        return 'ip:'.$this->fingerprint((string) $request->ip());
    }

    private function fingerprint(string $value): string
    {
        return hash_hmac('sha256', $value, (string) config('app.key'));
    }
}
