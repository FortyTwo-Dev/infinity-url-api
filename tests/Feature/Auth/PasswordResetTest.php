<?php

use App\Mail\ResetPassword;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;

it('sends one reset link to a known address and nothing to an unknown one', function () {
    Mail::fake();

    $workspace = Workspace::factory()->create();
    $user = User::factory()->create(['email' => 'forgot@example.com']);
    $workspace->members()->attach($user->id, ['role' => 'owner']);

    $known = $this->postJson('https://api.url.infinityproject.fr/v1/auth/password/forgot', [
        'email' => 'forgot@example.com',
    ]);

    $known->assertStatus(202);
    expect((string) $known->getContent())->toBe('');
    Mail::assertSentCount(1);
    Mail::assertSent(ResetPassword::class, fn (ResetPassword $mail): bool => $mail->hasTo('forgot@example.com'));

    $unknown = $this->postJson('https://api.url.infinityproject.fr/v1/auth/password/forgot', [
        'email' => 'nobody@example.com',
    ]);

    $unknown->assertStatus(202);
    expect((string) $unknown->getContent())->toBe('');
    Mail::assertSentCount(1);
});

it('resets the password once, revokes every refresh family and burns the token', function () {
    Mail::fake();

    $workspace = Workspace::factory()->create();
    $user = User::factory()->create(['email' => 'reset@example.com']);
    $workspace->members()->attach($user->id, ['role' => 'owner']);

    $firstLogin = $this->postJson('https://api.url.infinityproject.fr/v1/auth/login', [
        'email' => 'reset@example.com',
        'password' => 'password',
    ]);
    $firstLogin->assertStatus(200);
    $firstCookie = $firstLogin->getCookie('__Host-iurl_refresh', false)->getValue();

    $secondLogin = $this->postJson('https://api.url.infinityproject.fr/v1/auth/login', [
        'email' => 'reset@example.com',
        'password' => 'password',
    ]);
    $secondLogin->assertStatus(200);
    $secondCookie = $secondLogin->getCookie('__Host-iurl_refresh', false)->getValue();

    $forgot = $this->postJson('https://api.url.infinityproject.fr/v1/auth/password/forgot', [
        'email' => 'reset@example.com',
    ]);
    $forgot->assertStatus(202);

    $resetUrl = Mail::sent(ResetPassword::class)->firstOrFail()->url;
    parse_str((string) parse_url($resetUrl, PHP_URL_QUERY), $query);
    expect($query)->toHaveKey('token');
    $token = $query['token'];

    $reset = $this->postJson('https://api.url.infinityproject.fr/v1/auth/password/reset', [
        'token' => $token,
        'password' => 'a brand new password',
    ]);

    $reset->assertStatus(204);

    $this->postJson('https://api.url.infinityproject.fr/v1/auth/login', [
        'email' => 'reset@example.com',
        'password' => 'a brand new password',
    ])->assertStatus(200);

    $this->postJson('https://api.url.infinityproject.fr/v1/auth/login', [
        'email' => 'reset@example.com',
        'password' => 'password',
    ])->assertStatus(401);

    $replay = $this->postJson('https://api.url.infinityproject.fr/v1/auth/password/reset', [
        'token' => $token,
        'password' => 'yet another password',
    ]);

    $replay->assertStatus(422);
    expect((string) $replay->headers->get('Content-Type'))->toStartWith('application/problem+json');
    expect($replay->json('code'))->toBe('validation_failed');
    expect($replay->json('errors'))->toHaveKey('token');

    $this->withCredentials();

    $this->withUnencryptedCookie('__Host-iurl_refresh', $firstCookie)
        ->postJson('https://api.url.infinityproject.fr/v1/auth/refresh')
        ->assertStatus(401);

    $this->withUnencryptedCookie('__Host-iurl_refresh', $secondCookie)
        ->postJson('https://api.url.infinityproject.fr/v1/auth/refresh')
        ->assertStatus(401);
});

it('rejects a reset token whose cache entry has expired', function () {
    Mail::fake();

    $workspace = Workspace::factory()->create();
    $user = User::factory()->create(['email' => 'oldreset@example.com']);
    $workspace->members()->attach($user->id, ['role' => 'owner']);

    $this->postJson('https://api.url.infinityproject.fr/v1/auth/password/forgot', [
        'email' => 'oldreset@example.com',
    ])->assertStatus(202);

    $resetUrl = Mail::sent(ResetPassword::class)->firstOrFail()->url;
    parse_str((string) parse_url($resetUrl, PHP_URL_QUERY), $query);
    $token = $query['token'] ?? '';
    expect($token)->toBeString()->not->toBe('');

    // The reset token lives only in the cache with a one hour TTL; ageing the
    // clock does not shorten a real Valkey TTL, so the entry is stored again
    // with a one second lifetime to observe its expiry.
    $key = 'password-reset:'.$user->public_id;
    $stored = Cache::get($key);
    expect($stored)->not->toBeNull();

    Cache::put($key, $stored, 1);
    sleep(2);

    $response = $this->postJson('https://api.url.infinityproject.fr/v1/auth/password/reset', [
        'token' => $token,
        'password' => 'a brand new password',
    ]);

    $response->assertStatus(422);
    expect($response->json('code'))->toBe('validation_failed');
    expect($response->json('errors'))->toHaveKey('token');
});

it('changes the password and keeps only the current refresh family alive', function () {
    $workspace = Workspace::factory()->create();
    $user = User::factory()->create(['email' => 'change@example.com']);
    $workspace->members()->attach($user->id, ['role' => 'owner']);

    $currentLogin = $this->postJson('https://api.url.infinityproject.fr/v1/auth/login', [
        'email' => 'change@example.com',
        'password' => 'password',
    ]);
    $currentLogin->assertStatus(200);
    $currentAccess = $currentLogin->json('access_token');
    $currentCookie = $currentLogin->getCookie('__Host-iurl_refresh', false)->getValue();

    $otherLogin = $this->postJson('https://api.url.infinityproject.fr/v1/auth/login', [
        'email' => 'change@example.com',
        'password' => 'password',
    ]);
    $otherLogin->assertStatus(200);
    $otherCookie = $otherLogin->getCookie('__Host-iurl_refresh', false)->getValue();

    $this->withCredentials();

    $response = $this->withToken($currentAccess)
        ->withUnencryptedCookie('__Host-iurl_refresh', $currentCookie)
        ->patchJson('https://api.url.infinityproject.fr/v1/me/password', [
            'current_password' => 'password',
            'password' => 'a brand new password',
        ]);

    $response->assertStatus(204);

    $this->postJson('https://api.url.infinityproject.fr/v1/auth/login', [
        'email' => 'change@example.com',
        'password' => 'a brand new password',
    ])->assertStatus(200);

    $this->flushHeaders();
    $this->app['auth']->forgetGuards();

    $this->withUnencryptedCookie('__Host-iurl_refresh', $currentCookie)
        ->postJson('https://api.url.infinityproject.fr/v1/auth/refresh')
        ->assertStatus(200);

    $this->withUnencryptedCookie('__Host-iurl_refresh', $otherCookie)
        ->postJson('https://api.url.infinityproject.fr/v1/auth/refresh')
        ->assertStatus(401);
});

it('rejects a password change with the wrong current password', function () {
    $workspace = Workspace::factory()->create();
    $user = User::factory()->create(['email' => 'wrongcurrent@example.com']);
    $workspace->members()->attach($user->id, ['role' => 'owner']);

    $login = $this->postJson('https://api.url.infinityproject.fr/v1/auth/login', [
        'email' => 'wrongcurrent@example.com',
        'password' => 'password',
    ]);
    $login->assertStatus(200);

    $response = $this->withToken($login->json('access_token'))
        ->patchJson('https://api.url.infinityproject.fr/v1/me/password', [
            'current_password' => 'not-the-password',
            'password' => 'a brand new password',
        ]);

    $response->assertStatus(422);
    expect((string) $response->headers->get('Content-Type'))->toStartWith('application/problem+json');
    expect($response->json('code'))->toBe('validation_failed');
    expect($response->json('errors'))->toHaveKey('current_password');
});
