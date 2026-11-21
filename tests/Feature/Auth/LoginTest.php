<?php

it('logs in with an access token and a secure refresh cookie', function () {
    [, $user] = $this->account('login@example.com');

    $response = $this->login('login@example.com');

    $body = $response->json();
    expect(array_keys($body))->toEqualCanonicalizing(['access_token', 'token_type', 'expires_in', 'user']);
    expect($body['access_token'])->toBeString()->not->toBe('');
    expect($body['token_type'])->toBe('Bearer');
    expect($body['expires_in'])->toBe(900);

    expect(array_keys($body['user']))->toEqualCanonicalizing(['id', 'email', 'email_verified', 'totp_enabled']);
    expect($body['user']['id'])->toBe($user->public_id);
    expect($body['user']['email'])->toBe('login@example.com');
    expect($body['user']['email_verified'])->toBeTrue();
    expect($body['user']['totp_enabled'])->toBeFalse();

    $cookie = $response->getCookie('__Host-iurl_refresh', false);
    expect($cookie)->not->toBeNull();
    expect($cookie->getValue())->toBeString()->not->toBe('');
    expect($cookie->isHttpOnly())->toBeTrue();
    expect($cookie->isSecure())->toBeTrue();
    expect(strtolower((string) $cookie->getSameSite()))->toBe('lax');
    expect($cookie->getPath())->toBe('/');
    expect($cookie->getDomain() ?: null)->toBeNull();
    expect($cookie->getExpiresTime())->toBeGreaterThan(now()->addDays(29)->getTimestamp());
});

it('answers the same unauthorized problem for a wrong password and an unknown address', function () {
    $this->account('victim@example.com');

    $wrongPassword = $this->postJson('https://api.url.infinityproject.fr/v1/auth/login', [
        'email' => 'victim@example.com',
        'password' => 'not-the-password',
    ]);

    $unknownEmail = $this->postJson('https://api.url.infinityproject.fr/v1/auth/login', [
        'email' => 'nobody@example.com',
        'password' => 'not-the-password',
    ]);

    foreach ([$wrongPassword, $unknownEmail] as $response) {
        $this->assertProblem($response, 401, 'unauthorized');
        $response->assertCookieMissing('__Host-iurl_refresh');
    }

    expect(array_keys($unknownEmail->json()))->toBe(array_keys($wrongPassword->json()));
});

it('accepts the access token on me and rejects the refresh token as a bearer', function () {
    $this->account('bearer@example.com');

    $session = $this->loginSession('bearer@example.com');

    $me = $this->withToken($session->accessToken)->getJson('https://api.url.infinityproject.fr/v1/me');
    $me->assertStatus(200);
    expect($me->json('user.email'))->toBe('bearer@example.com');

    $this->app['auth']->forgetGuards();

    $rejected = $this->withToken((string) $session->cookie)
        ->getJson('https://api.url.infinityproject.fr/v1/me');
    expect($rejected->status())->toBeIn([401, 403]);
    expect($rejected->json('user'))->toBeNull();
});

it('asks for a totp challenge before issuing a session', function () {
    [, $user] = $this->account('twofactor@example.com');

    $enable = $this->enableTotp($user);
    $secret = $enable->json('secret');

    $confirm = $this->confirmTotp($secret);
    expect($confirm->json('recovery_codes'))->toHaveCount(10);

    // The confirmation consumed the current step; the next step stays inside
    // the accepted window and is not a replay.
    $code = $this->nextTotpCode($secret);

    $this->app['auth']->forgetGuards();

    $login = $this->postJson('https://api.url.infinityproject.fr/v1/auth/login', [
        'email' => 'twofactor@example.com',
        'password' => 'password',
    ]);

    $login->assertStatus(200);
    expect(array_keys($login->json()))->toEqualCanonicalizing(['status', 'challenge']);
    expect($login->json('status'))->toBe('totp_required');
    expect($login->json('challenge'))->toBeString()->not->toBe('');
    $login->assertCookieMissing('__Host-iurl_refresh');
    expect($login->json('access_token'))->toBeNull();

    $challenge = $login->json('challenge');

    $verified = $this->postJson('https://api.url.infinityproject.fr/v1/auth/2fa/verify', [
        'challenge' => $challenge,
        'code' => $code,
    ]);

    $verified->assertStatus(200);
    expect($verified->json('access_token'))->toBeString()->not->toBe('');
    expect($verified->json('token_type'))->toBe('Bearer');
    expect($verified->json('expires_in'))->toBe(900);
    expect($verified->json('user.email'))->toBe('twofactor@example.com');
    expect($verified->getCookie('__Host-iurl_refresh', false))->not->toBeNull();

    $replay = $this->postJson('https://api.url.infinityproject.fr/v1/auth/2fa/verify', [
        'challenge' => $challenge,
        'code' => $code,
    ]);

    $this->assertProblem($replay, 422, 'totp_invalid');
    $replay->assertCookieMissing('__Host-iurl_refresh');
});
