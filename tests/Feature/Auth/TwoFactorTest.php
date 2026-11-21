<?php

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use PragmaRX\Google2FA\Google2FA;

it('enables two factor authentication, returns ten recovery codes and reports it on me', function () {
    $workspace = Workspace::factory()->create();
    $user = User::factory()->create(['email' => 'enable@example.com']);
    $workspace->members()->attach($user->id, ['role' => 'owner']);

    Sanctum::actingAs($user, ['me:write']);

    $enable = $this->postJson('https://api.url.infinityproject.fr/v1/auth/2fa/enable');
    $enable->assertStatus(200);

    $secret = $enable->json('secret');
    $otpauthUrl = $enable->json('otpauth_url');

    expect($secret)->toBeString()->not->toBe('');
    expect($otpauthUrl)->toBeString()->toStartWith('otpauth://');
    expect($otpauthUrl)->toContain('secret='.$secret);

    $confirm = $this->postJson('https://api.url.infinityproject.fr/v1/auth/2fa/confirm', [
        'code' => (new Google2FA)->getCurrentOtp($secret),
    ]);

    $confirm->assertStatus(200);

    $codes = $confirm->json('recovery_codes');
    expect($codes)->toHaveCount(10);
    expect(array_unique($codes))->toHaveCount(10);
    expect(DB::table('recovery_codes')->where('user_id', $user->id)->count())->toBe(10);

    Sanctum::actingAs($user->fresh(), ['me:read']);

    $me = $this->getJson('https://api.url.infinityproject.fr/v1/me');
    $me->assertStatus(200);
    expect($me->json('user.totp_enabled'))->toBeTrue();

    $fresh = $user->fresh();
    expect($fresh->totp_confirmed_at)->not->toBeNull();
    expect($fresh->totp_secret)->toBe($secret);
});

it('refuses to confirm two factor authentication twice', function () {
    $workspace = Workspace::factory()->create();
    $user = User::factory()->create(['email' => 'confirmtwice@example.com']);
    $workspace->members()->attach($user->id, ['role' => 'owner']);

    $google2fa = new Google2FA;

    Sanctum::actingAs($user, ['me:write']);

    $enable = $this->postJson('https://api.url.infinityproject.fr/v1/auth/2fa/enable');
    $enable->assertStatus(200);
    $secret = $enable->json('secret');

    $this->postJson('https://api.url.infinityproject.fr/v1/auth/2fa/confirm', [
        'code' => $google2fa->getCurrentOtp($secret),
    ])->assertStatus(200);

    $second = $this->postJson('https://api.url.infinityproject.fr/v1/auth/2fa/confirm', [
        'code' => $google2fa->oathTotp($secret, $google2fa->getTimestamp() + 1),
    ]);

    $second->assertStatus(409);
    expect((string) $second->headers->get('Content-Type'))->toStartWith('application/problem+json');
    expect($second->json('code'))->toBe('totp_already_enabled');
});

it('completes a login with a recovery code once and keeps the other codes usable', function () {
    $workspace = Workspace::factory()->create();
    $user = User::factory()->create(['email' => 'recovery@example.com']);
    $workspace->members()->attach($user->id, ['role' => 'owner']);

    $google2fa = new Google2FA;

    Sanctum::actingAs($user, ['me:write']);

    $enable = $this->postJson('https://api.url.infinityproject.fr/v1/auth/2fa/enable');
    $enable->assertStatus(200);
    $secret = $enable->json('secret');

    $confirm = $this->postJson('https://api.url.infinityproject.fr/v1/auth/2fa/confirm', [
        'code' => $google2fa->getCurrentOtp($secret),
    ]);
    $confirm->assertStatus(200);
    $codes = $confirm->json('recovery_codes');

    $firstChallenge = $this->postJson('https://api.url.infinityproject.fr/v1/auth/login', [
        'email' => 'recovery@example.com',
        'password' => 'password',
    ])->json('challenge');

    $first = $this->postJson('https://api.url.infinityproject.fr/v1/auth/2fa/verify', [
        'challenge' => $firstChallenge,
        'recovery_code' => $codes[0],
    ]);

    $first->assertStatus(200);
    expect($first->json('access_token'))->toBeString()->not->toBe('');
    expect($first->json('user.email'))->toBe('recovery@example.com');
    expect($first->getCookie('__Host-iurl_refresh', false))->not->toBeNull();

    $secondChallenge = $this->postJson('https://api.url.infinityproject.fr/v1/auth/login', [
        'email' => 'recovery@example.com',
        'password' => 'password',
    ])->json('challenge');

    $reused = $this->postJson('https://api.url.infinityproject.fr/v1/auth/2fa/verify', [
        'challenge' => $secondChallenge,
        'recovery_code' => $codes[0],
    ]);

    $reused->assertStatus(422);
    expect($reused->json('code'))->toBe('recovery_code_invalid');
    $reused->assertCookieMissing('__Host-iurl_refresh');

    $thirdChallenge = $this->postJson('https://api.url.infinityproject.fr/v1/auth/login', [
        'email' => 'recovery@example.com',
        'password' => 'password',
    ])->json('challenge');

    $this->postJson('https://api.url.infinityproject.fr/v1/auth/2fa/verify', [
        'challenge' => $thirdChallenge,
        'recovery_code' => $codes[1],
    ])->assertStatus(200);
});

it('disables two factor authentication with the password and a totp code', function () {
    $google2fa = new Google2FA;
    $secret = $google2fa->generateSecretKey();

    $workspace = Workspace::factory()->create();
    $user = User::factory()->create(['email' => 'disable@example.com']);
    $workspace->members()->attach($user->id, ['role' => 'owner']);
    $user->forceFill(['totp_secret' => $secret, 'totp_confirmed_at' => now()])->save();

    Sanctum::actingAs($user, ['me:write']);

    $response = $this->postJson('https://api.url.infinityproject.fr/v1/auth/2fa/disable', [
        'password' => 'password',
        'code' => $google2fa->getCurrentOtp($secret),
    ]);

    $response->assertStatus(204);

    $fresh = $user->fresh();
    expect($fresh->totp_secret)->toBeNull();
    expect($fresh->totp_confirmed_at)->toBeNull();
    expect(DB::table('recovery_codes')->where('user_id', $user->id)->count())->toBe(0);

    $login = $this->postJson('https://api.url.infinityproject.fr/v1/auth/login', [
        'email' => 'disable@example.com',
        'password' => 'password',
    ]);

    $login->assertStatus(200);
    expect($login->json('access_token'))->toBeString()->not->toBe('');
    expect($login->json('status'))->toBeNull();
    expect($login->getCookie('__Host-iurl_refresh', false))->not->toBeNull();
});

it('rejects disabling two factor authentication with a wrong password', function () {
    $google2fa = new Google2FA;
    $secret = $google2fa->generateSecretKey();

    $workspace = Workspace::factory()->create();
    $user = User::factory()->create(['email' => 'wrongpass@example.com']);
    $workspace->members()->attach($user->id, ['role' => 'owner']);
    $user->forceFill(['totp_secret' => $secret, 'totp_confirmed_at' => now()])->save();

    Sanctum::actingAs($user, ['me:write']);

    $response = $this->postJson('https://api.url.infinityproject.fr/v1/auth/2fa/disable', [
        'password' => 'not-the-password',
        'code' => $google2fa->getCurrentOtp($secret),
    ]);

    $response->assertStatus(422);
    expect((string) $response->headers->get('Content-Type'))->toStartWith('application/problem+json');
    expect($response->json('code'))->toBe('validation_failed');
    expect($response->json('errors'))->toHaveKey('password');
    expect($user->fresh()->totp_confirmed_at)->not->toBeNull();
});

it('regenerates ten recovery codes and invalidates every old one', function () {
    $workspace = Workspace::factory()->create();
    $user = User::factory()->create(['email' => 'regenerate@example.com']);
    $workspace->members()->attach($user->id, ['role' => 'owner']);

    $google2fa = new Google2FA;

    Sanctum::actingAs($user, ['me:write']);

    $enable = $this->postJson('https://api.url.infinityproject.fr/v1/auth/2fa/enable');
    $enable->assertStatus(200);
    $secret = $enable->json('secret');

    $confirm = $this->postJson('https://api.url.infinityproject.fr/v1/auth/2fa/confirm', [
        'code' => $google2fa->getCurrentOtp($secret),
    ]);
    $confirm->assertStatus(200);
    $oldCodes = $confirm->json('recovery_codes');
    $oldHashes = DB::table('recovery_codes')->where('user_id', $user->id)->pluck('code_hash')->all();

    // The confirmation consumed the current step; the next step stays inside
    // the accepted window and is not a replay.
    $regenerate = $this->postJson('https://api.url.infinityproject.fr/v1/auth/2fa/recovery-codes', [
        'password' => 'password',
        'code' => $google2fa->oathTotp($secret, $google2fa->getTimestamp() + 1),
    ]);

    $regenerate->assertStatus(200);

    $newCodes = $regenerate->json('recovery_codes');
    expect($newCodes)->toHaveCount(10);
    expect(array_intersect($oldCodes, $newCodes))->toBe([]);
    expect(DB::table('recovery_codes')->where('user_id', $user->id)->count())->toBe(10);
    expect(DB::table('recovery_codes')->whereIn('code_hash', $oldHashes)->count())->toBe(0);

    foreach (array_slice($oldCodes, 0, 3) as $oldCode) {
        $challenge = $this->postJson('https://api.url.infinityproject.fr/v1/auth/login', [
            'email' => 'regenerate@example.com',
            'password' => 'password',
        ])->json('challenge');

        $rejected = $this->postJson('https://api.url.infinityproject.fr/v1/auth/2fa/verify', [
            'challenge' => $challenge,
            'recovery_code' => $oldCode,
        ]);

        $rejected->assertStatus(422);
        expect($rejected->json('code'))->toBe('recovery_code_invalid');
    }

    $challenge = $this->postJson('https://api.url.infinityproject.fr/v1/auth/login', [
        'email' => 'regenerate@example.com',
        'password' => 'password',
    ])->json('challenge');

    $this->postJson('https://api.url.infinityproject.fr/v1/auth/2fa/verify', [
        'challenge' => $challenge,
        'recovery_code' => $newCodes[0],
    ])->assertStatus(200);
});

it('requires the me write ability for every two factor mutation', function () {
    $workspace = Workspace::factory()->create();
    $user = User::factory()->create();
    $workspace->members()->attach($user->id, ['role' => 'owner']);

    Sanctum::actingAs($user, ['me:read']);

    $requests = [
        'https://api.url.infinityproject.fr/v1/auth/2fa/enable',
        'https://api.url.infinityproject.fr/v1/auth/2fa/confirm',
        'https://api.url.infinityproject.fr/v1/auth/2fa/disable',
        'https://api.url.infinityproject.fr/v1/auth/2fa/recovery-codes',
    ];

    foreach ($requests as $url) {
        $response = $this->postJson($url, ['code' => '000000']);

        $response->assertStatus(403);
        expect((string) $response->headers->get('Content-Type'))->toStartWith('application/problem+json');
        expect($response->json('code'))->toBe('scope_missing');
    }

    expect($user->fresh()->totp_confirmed_at)->toBeNull();
    expect(DB::table('recovery_codes')->count())->toBe(0);
});
