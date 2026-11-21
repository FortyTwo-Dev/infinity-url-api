<?php

use App\Mail\ResetPassword;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Tests\Support\Api;

const FORGOT_EMAIL = 'forgot@example.com';
const RESET_EMAIL = 'reset@example.com';
const CHANGE_EMAIL = 'change@example.com';
const NEW_PASSWORD = 'a brand new password';

it('sends one reset link to a known address and nothing to an unknown one', function () {
    Mail::fake();

    $this->account(FORGOT_EMAIL);

    $known = $this->postJson(Api::PASSWORD_FORGOT, [
        'email' => FORGOT_EMAIL,
    ]);

    $known->assertStatus(202);
    expect((string) $known->getContent())->toBe('');
    Mail::assertSentCount(1);
    Mail::assertSent(ResetPassword::class, fn (ResetPassword $mail): bool => $mail->hasTo(FORGOT_EMAIL));

    $unknown = $this->postJson(Api::PASSWORD_FORGOT, [
        'email' => 'nobody@example.com',
    ]);

    $unknown->assertStatus(202);
    expect((string) $unknown->getContent())->toBe('');
    Mail::assertSentCount(1);
});

it('resets the password once, revokes every refresh family and burns the token', function () {
    Mail::fake();

    $this->account(RESET_EMAIL);

    $first = $this->loginSession(RESET_EMAIL);
    $second = $this->loginSession(RESET_EMAIL);

    $this->requestReset(RESET_EMAIL);
    $token = $this->resetToken();

    $reset = $this->postJson(Api::PASSWORD_RESET, [
        'token' => $token,
        'password' => NEW_PASSWORD,
    ]);

    $reset->assertStatus(204);

    $this->login(RESET_EMAIL, NEW_PASSWORD);

    $this->postJson(Api::LOGIN, [
        'email' => RESET_EMAIL,
        'password' => 'password',
    ])->assertStatus(401);

    $replay = $this->postJson(Api::PASSWORD_RESET, [
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

    $response = $this->postJson(Api::PASSWORD_RESET, [
        'token' => $token,
        'password' => NEW_PASSWORD,
    ]);

    $this->assertProblem($response, 422, 'validation_failed');
    expect($response->json('errors'))->toHaveKey('token');
});

it('changes the password and keeps only the current refresh family alive', function () {
    $this->account(CHANGE_EMAIL);

    $current = $this->loginSession(CHANGE_EMAIL);
    $other = $this->loginSession(CHANGE_EMAIL);

    $response = $this->withToken($current->accessToken)
        ->withRefreshCookie($current->cookie)
        ->patchJson(Api::ME_PASSWORD, [
            'current_password' => 'password',
            'password' => NEW_PASSWORD,
        ]);

    $response->assertStatus(204);

    $this->login(CHANGE_EMAIL, NEW_PASSWORD);

    $this->flushHeaders();
    $this->app['auth']->forgetGuards();

    $this->refreshWith($current->cookie)->assertStatus(200);
    $this->refreshWith($other->cookie)->assertStatus(401);
});

it('rejects a password change with the wrong current password', function () {
    $this->account('wrongcurrent@example.com');

    $session = $this->loginSession('wrongcurrent@example.com');

    $response = $this->withToken($session->accessToken)
        ->patchJson(Api::ME_PASSWORD, [
            'current_password' => 'not-the-password',
            'password' => NEW_PASSWORD,
        ]);

    $this->assertProblem($response, 422, 'validation_failed');
    expect($response->json('errors'))->toHaveKey('current_password');
});
