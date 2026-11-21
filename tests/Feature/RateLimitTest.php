<?php

use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Tests\Support\Api;

const BRUTEFORCE_EMAIL = 'bruteforce@example.com';
const RESEND_LIMIT_EMAIL = 'resendlimit@example.com';

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
    $this->account(BRUTEFORCE_EMAIL);

    for ($attempt = 1; $attempt <= 10; $attempt++) {
        $this->postJson(Api::LOGIN, [
            'email' => BRUTEFORCE_EMAIL,
            'password' => 'wrong-password',
        ])->assertStatus(401);
    }

    $limited = $this->postJson(Api::LOGIN, [
        'email' => BRUTEFORCE_EMAIL,
        'password' => 'wrong-password',
    ]);

    $this->assertProblem($limited, 429, 'rate_limited');
    expect((string) $limited->headers->get('RateLimit-Limit'))->toBe('10');
    expect((string) $limited->headers->get('RateLimit-Remaining'))->toBe('0');
    expect((int) $limited->headers->get('RateLimit-Reset'))->toBeGreaterThanOrEqual(0);
    expect($limited->headers->get('Retry-After'))->not->toBeNull();
});

it('exposes ietf rate limit headers on a successful call', function () {
    $this->account('headers@example.com');

    $accessToken = $this->loginSession('headers@example.com')->accessToken;

    $response = $this->withToken($accessToken)
        ->getJson(Api::ME);

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
        $this->postJson(Api::REGISTER, [
            'email' => 'batch'.$attempt.'@example.com',
            'password' => 'correct horse battery',
        ])->assertStatus(201);
    }

    $limited = $this->postJson(Api::REGISTER, [
        'email' => 'batch3@example.com',
        'password' => 'correct horse battery',
    ]);

    $this->assertProblem($limited, 429, 'rate_limited');
    expect($limited->headers->get('Retry-After'))->not->toBeNull();
    expect(User::count())->toBe(2);
});

it('answers 429 on the fourth verification resend within an hour', function () {
    Mail::fake();

    [, $user] = $this->account(RESEND_LIMIT_EMAIL);
    $user->forceFill(['email_verified_at' => null])->save();

    for ($attempt = 1; $attempt <= 3; $attempt++) {
        $this->postJson(Api::RESEND_VERIFICATION, [
            'email' => RESEND_LIMIT_EMAIL,
        ])->assertStatus(202);
    }

    $limited = $this->postJson(Api::RESEND_VERIFICATION, [
        'email' => RESEND_LIMIT_EMAIL,
    ]);

    $this->assertProblem($limited, 429, 'rate_limited');
    expect($limited->headers->get('Retry-After'))->not->toBeNull();
});

it('carries the ietf rate limit headers on an unauthenticated refresh', function () {
    $response = $this->postJson(Api::REFRESH);

    $response->assertStatus(401);
    expect((string) $response->headers->get('Content-Type'))->toStartWith(Api::PROBLEM_CONTENT_TYPE);
    expect($response->json('code'))->toBeIn(['token_revoked', 'unauthorized']);

    assertIetfRateLimitHeaders($response);
});

it('carries the ietf rate limit headers on an unknown v1 route', function () {
    $response = $this->getJson(Api::BASE.'/does-not-exist');

    $response->assertStatus(404);
    expect((string) $response->headers->get('Content-Type'))->toStartWith(Api::PROBLEM_CONTENT_TYPE);
    expect($response->json('code'))->toBe('not_found');
    expect($response->json('instance'))->toBe('/v1/does-not-exist');

    assertIetfRateLimitHeaders($response);
});

it('carries the ietf rate limit headers on an unauthenticated me request', function () {
    $response = $this->getJson(Api::ME);

    $response->assertStatus(401);
    expect($response->json('code'))->toBe('unauthorized');

    assertIetfRateLimitHeaders($response);
});

it('carries the ietf rate limit headers on the bare v1 path', function () {
    $response = $this->getJson(Api::BASE);

    $response->assertStatus(404);
    expect((string) $response->headers->get('Content-Type'))->toStartWith(Api::PROBLEM_CONTENT_TYPE);
    expect($response->json('code'))->toBe('not_found');
    expect($response->json('instance'))->toBe('/v1');

    assertIetfRateLimitHeaders($response);
});
