<?php

it('rotates the refresh cookie and revokes the whole family when the old one is replayed', function () {
    $this->account('rotate@example.com');

    $cookieA = $this->loginSession('rotate@example.com')->cookie;

    $refreshed = $this->refreshWith($cookieA);

    $refreshed->assertStatus(200);

    $body = $refreshed->json();
    expect(array_keys($body))->toEqualCanonicalizing(['access_token', 'token_type', 'expires_in']);
    expect($body['access_token'])->toBeString()->not->toBe('');
    expect($body['token_type'])->toBe('Bearer');
    expect($body['expires_in'])->toBe(900);

    $cookieB = $refreshed->getCookie('__Host-iurl_refresh', false)->getValue();
    expect($cookieB)->not->toBe($cookieA);

    $replay = $this->refreshWith($cookieA);

    $this->assertProblem($replay, 401, 'token_revoked');

    $this->refreshWith($cookieB)->assertStatus(401);

    $cookieC = $this->loginSession('rotate@example.com')->cookie;

    $this->refreshWith($cookieC)->assertStatus(200);
});

it('answers the same unauthorized code without a cookie and with an unknown cookie', function () {
    $this->withCredentials();

    $missing = $this->postJson('https://api.url.infinityproject.fr/v1/auth/refresh');

    $missing->assertStatus(401);
    expect((string) $missing->headers->get('Content-Type'))->toStartWith('application/problem+json');
    $code = $missing->json('code');
    expect($code)->toBeIn(['token_revoked', 'unauthorized']);

    $random = $this->withRefreshCookie('not-a-real-refresh-token')
        ->postJson('https://api.url.infinityproject.fr/v1/auth/refresh');

    $random->assertStatus(401);
    expect($random->json('code'))->toBe($code);
});

it('keeps a rotated family refreshing across several rotations', function () {
    $this->account('chain@example.com');

    $cookie = $this->loginSession('chain@example.com')->cookie;

    for ($i = 1; $i <= 3; $i++) {
        $refreshed = $this->refreshWith($cookie);

        $refreshed->assertStatus(200);
        $cookie = $refreshed->getCookie('__Host-iurl_refresh', false)->getValue();
    }

    $this->withToken($refreshed->json('access_token'))
        ->getJson('https://api.url.infinityproject.fr/v1/me')
        ->assertStatus(200);
});
