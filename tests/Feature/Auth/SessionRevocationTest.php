<?php

use App\Models\User;
use App\Models\Workspace;
use Laravel\Sanctum\Sanctum;

it('revokes only the current refresh family on logout', function () {
    $this->withCredentials();

    $workspace = Workspace::factory()->create();
    $user = User::factory()->create(['email' => 'logout@example.com']);
    $workspace->members()->attach($user->id, ['role' => 'owner']);

    $firstLogin = $this->postJson('https://api.url.infinityproject.fr/v1/auth/login', [
        'email' => 'logout@example.com',
        'password' => 'password',
    ]);
    $firstLogin->assertStatus(200);
    $firstCookie = $firstLogin->getCookie('__Host-iurl_refresh', false)->getValue();

    $secondLogin = $this->postJson('https://api.url.infinityproject.fr/v1/auth/login', [
        'email' => 'logout@example.com',
        'password' => 'password',
    ]);
    $secondLogin->assertStatus(200);
    $secondCookie = $secondLogin->getCookie('__Host-iurl_refresh', false)->getValue();

    $logout = $this->withUnencryptedCookie('__Host-iurl_refresh', $firstCookie)
        ->postJson('https://api.url.infinityproject.fr/v1/auth/logout');

    $logout->assertStatus(204);

    $this->withUnencryptedCookie('__Host-iurl_refresh', $firstCookie)
        ->postJson('https://api.url.infinityproject.fr/v1/auth/refresh')
        ->assertStatus(401);

    $this->withUnencryptedCookie('__Host-iurl_refresh', $secondCookie)
        ->postJson('https://api.url.infinityproject.fr/v1/auth/refresh')
        ->assertStatus(200);
});

it('revokes every refresh family on logout-all', function () {
    $workspace = Workspace::factory()->create();
    $user = User::factory()->create(['email' => 'logoutall@example.com']);
    $workspace->members()->attach($user->id, ['role' => 'owner']);

    $firstLogin = $this->postJson('https://api.url.infinityproject.fr/v1/auth/login', [
        'email' => 'logoutall@example.com',
        'password' => 'password',
    ]);
    $firstLogin->assertStatus(200);
    $firstAccess = $firstLogin->json('access_token');
    $firstCookie = $firstLogin->getCookie('__Host-iurl_refresh', false)->getValue();

    $secondLogin = $this->postJson('https://api.url.infinityproject.fr/v1/auth/login', [
        'email' => 'logoutall@example.com',
        'password' => 'password',
    ]);
    $secondLogin->assertStatus(200);
    $secondCookie = $secondLogin->getCookie('__Host-iurl_refresh', false)->getValue();

    $response = $this->withToken($firstAccess)
        ->postJson('https://api.url.infinityproject.fr/v1/auth/logout-all');

    $response->assertStatus(204);

    $this->flushHeaders();
    $this->app['auth']->forgetGuards();
    $this->withCredentials();

    $this->withUnencryptedCookie('__Host-iurl_refresh', $firstCookie)
        ->postJson('https://api.url.infinityproject.fr/v1/auth/refresh')
        ->assertStatus(401);

    $this->withUnencryptedCookie('__Host-iurl_refresh', $secondCookie)
        ->postJson('https://api.url.infinityproject.fr/v1/auth/refresh')
        ->assertStatus(401);
});

it('requires the me write ability to revoke every session', function () {
    $workspace = Workspace::factory()->create();
    $user = User::factory()->create();
    $workspace->members()->attach($user->id, ['role' => 'owner']);

    Sanctum::actingAs($user, ['me:read']);

    $response = $this->postJson('https://api.url.infinityproject.fr/v1/auth/logout-all');

    $response->assertStatus(403);
    expect((string) $response->headers->get('Content-Type'))->toStartWith('application/problem+json');
    expect($response->json('code'))->toBe('scope_missing');
});
