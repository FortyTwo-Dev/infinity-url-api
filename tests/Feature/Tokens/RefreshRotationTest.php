<?php

use App\Models\User;
use App\Models\Workspace;

it('rotates the refresh cookie and revokes the whole family when the old one is replayed', function () {
    $this->withCredentials();

    $workspace = Workspace::factory()->create();
    $user = User::factory()->create(['email' => 'rotate@example.com']);
    $workspace->members()->attach($user->id, ['role' => 'owner']);

    $login = $this->postJson('https://api.url.infinityproject.fr/v1/auth/login', [
        'email' => 'rotate@example.com',
        'password' => 'password',
    ]);
    $login->assertStatus(200);
    $cookieA = $login->getCookie('__Host-iurl_refresh', false)->getValue();

    $refreshed = $this->withUnencryptedCookie('__Host-iurl_refresh', $cookieA)
        ->postJson('https://api.url.infinityproject.fr/v1/auth/refresh');

    $refreshed->assertStatus(200);

    $body = $refreshed->json();
    expect(array_keys($body))->toEqualCanonicalizing(['access_token', 'token_type', 'expires_in']);
    expect($body['access_token'])->toBeString()->not->toBe('');
    expect($body['token_type'])->toBe('Bearer');
    expect($body['expires_in'])->toBe(900);

    $cookieB = $refreshed->getCookie('__Host-iurl_refresh', false)->getValue();
    expect($cookieB)->not->toBe($cookieA);

    $replay = $this->withUnencryptedCookie('__Host-iurl_refresh', $cookieA)
        ->postJson('https://api.url.infinityproject.fr/v1/auth/refresh');

    $replay->assertStatus(401);
    expect((string) $replay->headers->get('Content-Type'))->toStartWith('application/problem+json');
    expect($replay->json('code'))->toBe('token_revoked');

    $familyRevoked = $this->withUnencryptedCookie('__Host-iurl_refresh', $cookieB)
        ->postJson('https://api.url.infinityproject.fr/v1/auth/refresh');

    $familyRevoked->assertStatus(401);

    $freshLogin = $this->postJson('https://api.url.infinityproject.fr/v1/auth/login', [
        'email' => 'rotate@example.com',
        'password' => 'password',
    ]);
    $freshLogin->assertStatus(200);
    $cookieC = $freshLogin->getCookie('__Host-iurl_refresh', false)->getValue();

    $this->withUnencryptedCookie('__Host-iurl_refresh', $cookieC)
        ->postJson('https://api.url.infinityproject.fr/v1/auth/refresh')
        ->assertStatus(200);
});

it('answers the same unauthorized code without a cookie and with an unknown cookie', function () {
    $this->withCredentials();

    $missing = $this->postJson('https://api.url.infinityproject.fr/v1/auth/refresh');

    $missing->assertStatus(401);
    expect((string) $missing->headers->get('Content-Type'))->toStartWith('application/problem+json');
    $code = $missing->json('code');
    expect($code)->toBeIn(['token_revoked', 'unauthorized']);

    $random = $this->withUnencryptedCookie('__Host-iurl_refresh', 'not-a-real-refresh-token')
        ->postJson('https://api.url.infinityproject.fr/v1/auth/refresh');

    $random->assertStatus(401);
    expect($random->json('code'))->toBe($code);
});

it('keeps a rotated family refreshing across several rotations', function () {
    $this->withCredentials();

    $workspace = Workspace::factory()->create();
    $user = User::factory()->create(['email' => 'chain@example.com']);
    $workspace->members()->attach($user->id, ['role' => 'owner']);

    $login = $this->postJson('https://api.url.infinityproject.fr/v1/auth/login', [
        'email' => 'chain@example.com',
        'password' => 'password',
    ]);
    $login->assertStatus(200);
    $cookie = $login->getCookie('__Host-iurl_refresh', false)->getValue();

    for ($i = 1; $i <= 3; $i++) {
        $refreshed = $this->withUnencryptedCookie('__Host-iurl_refresh', $cookie)
            ->postJson('https://api.url.infinityproject.fr/v1/auth/refresh');

        $refreshed->assertStatus(200);
        $cookie = $refreshed->getCookie('__Host-iurl_refresh', false)->getValue();
    }

    $this->withToken($refreshed->json('access_token'))
        ->getJson('https://api.url.infinityproject.fr/v1/me')
        ->assertStatus(200);
});
