<?php

use App\Mail\ResetPassword;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;

it('sends one reset link to a known address and nothing to an unknown one', function () {
    Mail::fake();

    $this->account('forgot@example.com');

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

    $this->account('reset@example.com');

    $first = $this->loginSession('reset@example.com');
    $second = $this->loginSession('reset@example.com');

    $this->requestReset('reset@example.com');
    $token = $this->resetToken();

    $reset = $this->postJson('https://api.url.infinityproject.fr/v1/auth/password/reset', [
        'token' => $token,
        'password' => 'a brand new password',
    ]);

    $reset->assertStatus(204);

    $this->login('reset@example.com', 'a brand new password');

    $this->postJson('https://api.url.infinityproject.fr/v1/auth/login', [
        'email' => 'reset@example.com',
        'password' => 'password',
    ])->assertStatus(401);

    $replay = $this->postJson('https://api.url.infinityproject.fr/v1/auth/password/reset', [
        'token' => $token,
        'password' => 'yet another password',
    ]);

    $this->assertProblem($replay, 422, 'validation_failed');
    expect($replay->json('errors'))->toHaveKey('token');

    $this->refreshWith($first->cookie)->assertStatus(401);
    $this->refreshWith($second->cookie)->assertStatus(401);
});

it('rejects a reset token whose cache entry has expired', function () {
    Mail::fake();

    $user = $this->account('oldreset@example.com')[1];

    $this->requestReset('oldreset@example.com');
    $token = $this->resetToken();

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

    $this->assertProblem($response, 422, 'validation_failed');
    expect($response->json('errors'))->toHaveKey('token');
});

it('changes the password and keeps only the current refresh family alive', function () {
    $this->account('change@example.com');

    $current = $this->loginSession('change@example.com');
    $other = $this->loginSession('change@example.com');

    $response = $this->withToken($current->accessToken)
        ->withRefreshCookie($current->cookie)
        ->patchJson('https://api.url.infinityproject.fr/v1/me/password', [
            'current_password' => 'password',
            'password' => 'a brand new password',
        ]);

    $response->assertStatus(204);

    $this->login('change@example.com', 'a brand new password');

    $this->flushHeaders();
    $this->app['auth']->forgetGuards();

    $this->refreshWith($current->cookie)->assertStatus(200);
    $this->refreshWith($other->cookie)->assertStatus(401);
});

it('rejects a password change with the wrong current password', function () {
    $this->account('wrongcurrent@example.com');

    $session = $this->loginSession('wrongcurrent@example.com');

    $response = $this->withToken($session->accessToken)
        ->patchJson('https://api.url.infinityproject.fr/v1/me/password', [
            'current_password' => 'not-the-password',
            'password' => 'a brand new password',
        ]);

    $this->assertProblem($response, 422, 'validation_failed');
    expect($response->json('errors'))->toHaveKey('current_password');
});
