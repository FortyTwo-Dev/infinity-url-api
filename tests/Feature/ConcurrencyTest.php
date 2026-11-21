<?php

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\Support\Api;
use Tests\Support\ProbeRunner;

const CONCURRENT_RESET_EMAIL = 'concurrent-reset@example.com';

it('lets exactly one of two simultaneous refresh attempts rotate the cookie', function () {
    if ($reason = ProbeRunner::unavailableReason()) {
        $this->markTestSkipped($reason);
    }

    $this->account('concurrent-refresh@example.com');

    $cookie = $this->loginSession('concurrent-refresh@example.com')->cookie;

    $results = ProbeRunner::run([
        [
            'url' => Api::REFRESH,
            'cookies' => ['__Host-iurl_refresh' => $cookie],
        ],
        [
            'url' => Api::REFRESH,
            'cookies' => ['__Host-iurl_refresh' => $cookie],
        ],
    ]);

    $statuses = array_column($results, 'status');
    sort($statuses);

    expect($statuses)->toBe([200, 401]);
    expect(count(array_filter(array_column($results, 'status'), fn (int $status): bool => $status === 200)))->toBe(1);

    $winner = collect($results)->firstWhere('status', 200);
    $loser = collect($results)->firstWhere('status', 401);

    expect($loser['body']['code'])->toBe('token_revoked');
    expect($winner['cookie'])->toBeString()->not->toBe('');

    // The replay revoked the whole family, including the token the winner got.
    $this->refreshWith($winner['cookie'])->assertStatus(401);
});

it('lets exactly one of two simultaneous totp verifications consume the code', function () {
    if ($reason = ProbeRunner::unavailableReason()) {
        $this->markTestSkipped($reason);
    }

    [, , $secret] = $this->totpAccount('concurrent-totp@example.com');

    $challenge = $this->challenge('concurrent-totp@example.com');
    $code = $this->totpCode($secret);

    $results = ProbeRunner::run([
        [
            'url' => Api::TWO_FACTOR_VERIFY,
            'body' => ['challenge' => $challenge, 'code' => $code],
        ],
        [
            'url' => Api::TWO_FACTOR_VERIFY,
            'body' => ['challenge' => $challenge, 'code' => $code],
        ],
    ]);

    $statuses = array_column($results, 'status');
    sort($statuses);

    expect(count(array_filter(array_column($results, 'status'), fn (int $status): bool => $status === 200)))->toBe(1);
    expect($statuses[0])->toBe(200);
    expect($statuses[1])->toBeIn([422, 503]);

    $loser = collect($results)->first(fn (array $result): bool => $result['status'] !== 200);

    if ($loser['status'] === 422) {
        expect($loser['body']['code'])->toBe('totp_invalid');
    }

    $winner = collect($results)->firstWhere('status', 200);
    expect($winner['body']['access_token'])->toBeString()->not->toBe('');
});

it('lets exactly one of two simultaneous password resets consume the token', function () {
    if ($reason = ProbeRunner::unavailableReason()) {
        $this->markTestSkipped($reason);
    }

    Mail::fake();

    $user = $this->account(CONCURRENT_RESET_EMAIL)[1];
    $this->requestReset(CONCURRENT_RESET_EMAIL);
    $token = $this->resetToken();

    $results = ProbeRunner::run([
        [
            'url' => Api::PASSWORD_RESET,
            'body' => ['token' => $token, 'password' => 'concurrent password one'],
        ],
        [
            'url' => Api::PASSWORD_RESET,
            'body' => ['token' => $token, 'password' => 'concurrent password two'],
        ],
    ]);

    $statuses = array_column($results, 'status');
    sort($statuses);

    expect($statuses)->toBe([204, 422]);

    $loser = collect($results)->firstWhere('status', 422);
    expect($loser['body']['code'])->toBe('validation_failed');
    expect($loser['body']['errors'])->toHaveKey('token');

    $one = $this->postJson(Api::LOGIN, [
        'email' => CONCURRENT_RESET_EMAIL,
        'password' => 'concurrent password one',
    ])->status();

    $two = $this->postJson(Api::LOGIN, [
        'email' => CONCURRENT_RESET_EMAIL,
        'password' => 'concurrent password two',
    ])->status();

    $logins = [$one, $two];
    sort($logins);
    expect($logins)->toBe([200, 401]);

    $replay = $this->postJson(Api::PASSWORD_RESET, [
        'token' => $token,
        'password' => 'concurrent password three',
    ]);

    $replay->assertStatus(422);
    expect($replay->json('code'))->toBe('validation_failed');
    expect($replay->json('errors'))->toHaveKey('token');

    expect(Cache::get('password-reset:'.$user->public_id))->toBeNull();
});

it('answers token_revoked for two consumed cookies of the same family replayed in parallel', function () {
    if ($reason = ProbeRunner::unavailableReason()) {
        $this->markTestSkipped($reason);
    }

    $this->account('concurrent-family@example.com');
    $cookieA = $this->loginSession('concurrent-family@example.com')->cookie;

    $first = $this->refreshWith($cookieA);
    $first->assertStatus(200);
    $cookieB = $first->getCookie('__Host-iurl_refresh', false)->getValue();

    $second = $this->refreshWith($cookieB);
    $second->assertStatus(200);
    $cookieC = $second->getCookie('__Host-iurl_refresh', false)->getValue();

    $results = ProbeRunner::run([
        [
            'url' => Api::REFRESH,
            'cookies' => ['__Host-iurl_refresh' => $cookieA],
        ],
        [
            'url' => Api::REFRESH,
            'cookies' => ['__Host-iurl_refresh' => $cookieB],
        ],
    ]);

    $statuses = array_column($results, 'status');
    sort($statuses);

    expect($statuses)->toBe([401, 401]);

    foreach ($results as $result) {
        expect($result['body']['code'])->toBe('token_revoked');
    }

    // Both consumed tokens were replayed, so the third (still active) token of
    // the family must be dead as well.
    $this->refreshWith($cookieC)->assertStatus(401);
});

it('serialises a concurrent 2fa disable and login verification on the same step', function () {
    if ($reason = ProbeRunner::unavailableReason()) {
        $this->markTestSkipped($reason);
    }

    [, $user, $secret] = $this->totpAccount('concurrent-disable@example.com');

    $access = $user->createToken('dashboard', ['*'])->plainTextToken;

    $challenge = $this->challenge('concurrent-disable@example.com');
    $code = $this->totpCode($secret);

    $results = ProbeRunner::run([
        [
            'url' => Api::TWO_FACTOR_DISABLE,
            'headers' => ['Authorization' => 'Bearer '.$access],
            'body' => ['password' => 'password', 'code' => $code],
        ],
        [
            'url' => Api::TWO_FACTOR_VERIFY,
            'body' => ['challenge' => $challenge, 'code' => $code],
        ],
    ]);

    $statuses = array_column($results, 'status');
    sort($statuses);

    expect(in_array(503, $statuses, true))->toBeFalse();

    $accepted = collect($results)->filter(fn (array $result): bool => in_array($result['status'], [200, 204], true))->values();
    $rejected = collect($results)->filter(fn (array $result): bool => $result['status'] === 422)->values();

    expect($accepted)->toHaveCount(1);
    expect($rejected)->toHaveCount(1);
    expect($rejected[0]['body']['code'])->toBe('totp_invalid');

    $fresh = User::query()->findOrFail($user->id);

    if ($accepted[0]['status'] === 204) {
        expect($fresh->totp_confirmed_at)->toBeNull();
        expect($fresh->totp_secret)->toBeNull();
        expect(DB::table('recovery_codes')->where('user_id', $user->id)->count())->toBe(0);
    } else {
        expect($fresh->totp_confirmed_at)->not->toBeNull();
        expect($fresh->totp_secret)->not->toBeNull();
    }
});
