<?php

use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Support\Api;

const RECOVERY_EMAIL = 'recovery@example.com';
const REGENERATE_EMAIL = 'regenerate@example.com';

it('enables two factor authentication, returns ten recovery codes and reports it on me', function () {
    [, $user] = $this->account('enable@example.com');

    $enable = $this->enableTotp($user);
    $secret = $enable->json('secret');
    $otpauthUrl = $enable->json('otpauth_url');

    expect($otpauthUrl)->toBeString()->toStartWith('otpauth://');
    expect($otpauthUrl)->toContain('secret='.$secret);

    $confirm = $this->confirmTotp($secret);

    $codes = $confirm->json('recovery_codes');
    expect($codes)->toHaveCount(10);
    expect(array_unique($codes))->toHaveCount(10);
    expect(DB::table('recovery_codes')->where('user_id', $user->id)->count())->toBe(10);

    Sanctum::actingAs($user->fresh(), ['me:read']);

    $me = $this->getJson(Api::ME);
    $me->assertStatus(200);
    expect($me->json('user.totp_enabled'))->toBeTrue();

    $fresh = $user->fresh();
    expect($fresh->totp_confirmed_at)->not->toBeNull();
    expect($fresh->totp_secret)->toBe($secret);
});

it('refuses to confirm two factor authentication twice', function () {
    [, $user] = $this->account('confirmtwice@example.com');

    $enable = $this->enableTotp($user);
    $secret = $enable->json('secret');

    $this->confirmTotp($secret);

    $second = $this->postJson(Api::TWO_FACTOR_CONFIRM, [
        'code' => $this->nextTotpCode($secret),
    ]);

    $second->assertStatus(409);
    expect((string) $second->headers->get('Content-Type'))->toStartWith(Api::PROBLEM_CONTENT_TYPE);
    expect($second->json('code'))->toBe('totp_already_enabled');
});

it('completes a login with a recovery code once and keeps the other codes usable', function () {
    [, $user] = $this->account(RECOVERY_EMAIL);

    $enable = $this->enableTotp($user);
    $secret = $enable->json('secret');

    $codes = $this->confirmTotp($secret)->json('recovery_codes');

    $firstChallenge = $this->challenge(RECOVERY_EMAIL);

    $first = $this->postJson(Api::TWO_FACTOR_VERIFY, [
        'challenge' => $firstChallenge,
        'recovery_code' => $codes[0],
    ]);

    $first->assertStatus(200);
    expect($first->json('access_token'))->toBeString()->not->toBe('');
    expect($first->json('user.email'))->toBe(RECOVERY_EMAIL);
    expect($first->getCookie('__Host-iurl_refresh', false))->not->toBeNull();

    $secondChallenge = $this->challenge(RECOVERY_EMAIL);

    $reused = $this->postJson(Api::TWO_FACTOR_VERIFY, [
        'challenge' => $secondChallenge,
        'recovery_code' => $codes[0],
    ]);

    $reused->assertStatus(422);
    expect($reused->json('code'))->toBe('recovery_code_invalid');
    $reused->assertCookieMissing('__Host-iurl_refresh');

    $thirdChallenge = $this->challenge(RECOVERY_EMAIL);

    $this->postJson(Api::TWO_FACTOR_VERIFY, [
        'challenge' => $thirdChallenge,
        'recovery_code' => $codes[1],
    ])->assertStatus(200);
});

it('disables two factor authentication with the password and a totp code', function () {
    [, $user, $secret] = $this->totpAccount('disable@example.com');

    Sanctum::actingAs($user, ['me:write']);

    $response = $this->postJson(Api::TWO_FACTOR_DISABLE, [
        'password' => 'password',
        'code' => $this->totpCode($secret),
    ]);

    $response->assertStatus(204);

    $fresh = $user->fresh();
    expect($fresh->totp_secret)->toBeNull();
    expect($fresh->totp_confirmed_at)->toBeNull();
    expect(DB::table('recovery_codes')->where('user_id', $user->id)->count())->toBe(0);

    $login = $this->login('disable@example.com');

    expect($login->json('access_token'))->toBeString()->not->toBe('');
    expect($login->json('status'))->toBeNull();
    expect($login->getCookie('__Host-iurl_refresh', false))->not->toBeNull();
});

it('rejects disabling two factor authentication with a wrong password', function () {
    [, $user, $secret] = $this->totpAccount('wrongpass@example.com');

    Sanctum::actingAs($user, ['me:write']);

    $response = $this->postJson(Api::TWO_FACTOR_DISABLE, [
        'password' => 'not-the-password',
        'code' => $this->totpCode($secret),
    ]);

    $this->assertProblem($response, 422, 'validation_failed');
    expect($response->json('errors'))->toHaveKey('password');
    expect($user->fresh()->totp_confirmed_at)->not->toBeNull();
});

it('regenerates ten recovery codes and invalidates every old one', function () {
    [, $user] = $this->account(REGENERATE_EMAIL);

    $enable = $this->enableTotp($user);
    $secret = $enable->json('secret');

    $confirm = $this->confirmTotp($secret);
    $oldCodes = $confirm->json('recovery_codes');
    $oldHashes = DB::table('recovery_codes')->where('user_id', $user->id)->pluck('code_hash')->all();

    // The confirmation consumed the current step; the next step stays inside
    // the accepted window and is not a replay.
    $regenerate = $this->postJson(Api::TWO_FACTOR_RECOVERY_CODES, [
        'password' => 'password',
        'code' => $this->nextTotpCode($secret),
    ]);

    $regenerate->assertStatus(200);

    $newCodes = $regenerate->json('recovery_codes');
    expect($newCodes)->toHaveCount(10);
    expect(array_intersect($oldCodes, $newCodes))->toBe([]);
    expect(DB::table('recovery_codes')->where('user_id', $user->id)->count())->toBe(10);
    expect(DB::table('recovery_codes')->whereIn('code_hash', $oldHashes)->count())->toBe(0);

    foreach (array_slice($oldCodes, 0, 3) as $oldCode) {
        $challenge = $this->challenge(REGENERATE_EMAIL);

        $rejected = $this->postJson(Api::TWO_FACTOR_VERIFY, [
            'challenge' => $challenge,
            'recovery_code' => $oldCode,
        ]);

        $rejected->assertStatus(422);
        expect($rejected->json('code'))->toBe('recovery_code_invalid');
    }

    $challenge = $this->challenge(REGENERATE_EMAIL);

    $this->postJson(Api::TWO_FACTOR_VERIFY, [
        'challenge' => $challenge,
        'recovery_code' => $newCodes[0],
    ])->assertStatus(200);
});

it('requires the me write ability for every two factor mutation', function () {
    [, $user] = $this->account();

    Sanctum::actingAs($user, ['me:read']);

    $requests = [
        Api::TWO_FACTOR_ENABLE,
        Api::TWO_FACTOR_CONFIRM,
        Api::TWO_FACTOR_DISABLE,
        Api::TWO_FACTOR_RECOVERY_CODES,
    ];

    foreach ($requests as $url) {
        $response = $this->postJson($url, ['code' => '000000']);

        $this->assertProblem($response, 403, 'scope_missing');
    }

    expect($user->fresh()->totp_confirmed_at)->toBeNull();
    expect(DB::table('recovery_codes')->count())->toBe(0);
});
