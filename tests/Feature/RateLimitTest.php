<?php

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;

/**
 * Assert the IETF rate limit headers the whole /v1 surface promises.
 */
function assertIetfRateLimitHeaders(TestResponse $response): void
{
    expect($response->headers->get('RateLimit-Limit'))->not->toBeNull();
    expect($response->headers->get('RateLimit-Remaining'))->not->toBeNull();
    expect($response->headers->get('RateLimit-Reset'))->not->toBeNull();

    expect((int) $response->headers->get('RateLimit-Limit'))->toBeGreaterThan(0);
    expect((int) $response->headers->get('RateLimit-Remaining'))->toBeGreaterThanOrEqual(0);
    expect((int) $response->headers->get('RateLimit-Reset'))->toBeGreaterThanOrEqual(0);

    $response->assertHeaderMissing('X-RateLimit-Limit');
    $response->assertHeaderMissing('X-RateLimit-Remaining');
    $response->assertHeaderMissing('X-RateLimit-Reset');
}

it('answers 429 on the eleventh wrong password', function () {
    $workspace = Workspace::factory()->create();
    $user = User::factory()->create(['email' => 'bruteforce@example.com']);
    $workspace->members()->attach($user->id, ['role' => 'owner']);

    for ($attempt = 1; $attempt <= 10; $attempt++) {
        $this->postJson('https://api.url.infinityproject.fr/v1/auth/login', [
            'email' => 'bruteforce@example.com',
            'password' => 'wrong-password',
        ])->assertStatus(401);
    }

    $limited = $this->postJson('https://api.url.infinityproject.fr/v1/auth/login', [
        'email' => 'bruteforce@example.com',
        'password' => 'wrong-password',
    ]);

    $limited->assertStatus(429);
    expect((string) $limited->headers->get('Content-Type'))->toStartWith('application/problem+json');
    expect($limited->json('code'))->toBe('rate_limited');
    expect((string) $limited->headers->get('RateLimit-Limit'))->toBe('10');
    expect((string) $limited->headers->get('RateLimit-Remaining'))->toBe('0');
    expect((int) $limited->headers->get('RateLimit-Reset'))->toBeGreaterThanOrEqual(0);
    expect($limited->headers->get('Retry-After'))->not->toBeNull();
});

it('exposes ietf rate limit headers on a successful call', function () {
    $workspace = Workspace::factory()->create();
    $user = User::factory()->create(['email' => 'headers@example.com']);
    $workspace->members()->attach($user->id, ['role' => 'owner']);

    $login = $this->postJson('https://api.url.infinityproject.fr/v1/auth/login', [
        'email' => 'headers@example.com',
        'password' => 'password',
    ]);
    $login->assertStatus(200);

    $response = $this->withToken($login->json('access_token'))
        ->getJson('https://api.url.infinityproject.fr/v1/me');

    $response->assertStatus(200);
    expect((string) $response->headers->get('RateLimit-Limit'))->toBe('120');
    expect((int) $response->headers->get('RateLimit-Remaining'))->toBe(119);
    expect((int) $response->headers->get('RateLimit-Reset'))->toBeGreaterThanOrEqual(0);

    $response->assertHeaderMissing('X-RateLimit-Limit');
    $response->assertHeaderMissing('X-RateLimit-Remaining');
    $response->assertHeaderMissing('X-RateLimit-Reset');
});

it('answers 429 on the third registration from the same address', function () {
    Mail::fake();

    for ($attempt = 1; $attempt <= 2; $attempt++) {
        $this->postJson('https://api.url.infinityproject.fr/v1/auth/register', [
            'email' => 'batch'.$attempt.'@example.com',
            'password' => 'correct horse battery',
        ])->assertStatus(201);
    }

    $limited = $this->postJson('https://api.url.infinityproject.fr/v1/auth/register', [
        'email' => 'batch3@example.com',
        'password' => 'correct horse battery',
    ]);

    $limited->assertStatus(429);
    expect($limited->json('code'))->toBe('rate_limited');
    expect($limited->headers->get('Retry-After'))->not->toBeNull();
    expect(User::count())->toBe(2);
});

it('answers 429 on the fourth verification resend within an hour', function () {
    Mail::fake();

    $workspace = Workspace::factory()->create();
    $user = User::factory()->unverified()->create(['email' => 'resendlimit@example.com']);
    $workspace->members()->attach($user->id, ['role' => 'owner']);

    for ($attempt = 1; $attempt <= 3; $attempt++) {
        $this->postJson('https://api.url.infinityproject.fr/v1/auth/verification/resend', [
            'email' => 'resendlimit@example.com',
        ])->assertStatus(202);
    }

    $limited = $this->postJson('https://api.url.infinityproject.fr/v1/auth/verification/resend', [
        'email' => 'resendlimit@example.com',
    ]);

    $limited->assertStatus(429);
    expect((string) $limited->headers->get('Content-Type'))->toStartWith('application/problem+json');
    expect($limited->json('code'))->toBe('rate_limited');
    expect($limited->headers->get('Retry-After'))->not->toBeNull();
});

it('carries the ietf rate limit headers on an unauthenticated refresh', function () {
    $response = $this->postJson('https://api.url.infinityproject.fr/v1/auth/refresh');

    $response->assertStatus(401);
    expect((string) $response->headers->get('Content-Type'))->toStartWith('application/problem+json');
    expect($response->json('code'))->toBeIn(['token_revoked', 'unauthorized']);

    assertIetfRateLimitHeaders($response);
});

it('carries the ietf rate limit headers on an unknown v1 route', function () {
    $response = $this->getJson('https://api.url.infinityproject.fr/v1/does-not-exist');

    $response->assertStatus(404);
    expect((string) $response->headers->get('Content-Type'))->toStartWith('application/problem+json');
    expect($response->json('code'))->toBe('not_found');
    expect($response->json('instance'))->toBe('/v1/does-not-exist');

    assertIetfRateLimitHeaders($response);
});

it('carries the ietf rate limit headers on an unauthenticated me request', function () {
    $response = $this->getJson('https://api.url.infinityproject.fr/v1/me');

    $response->assertStatus(401);
    expect($response->json('code'))->toBe('unauthorized');

    assertIetfRateLimitHeaders($response);
});

it('carries the ietf rate limit headers on the bare v1 path', function () {
    $response = $this->getJson('https://api.url.infinityproject.fr/v1');

    $response->assertStatus(404);
    expect((string) $response->headers->get('Content-Type'))->toStartWith('application/problem+json');
    expect($response->json('code'))->toBe('not_found');
    expect($response->json('instance'))->toBe('/v1');

    assertIetfRateLimitHeaders($response);
});
