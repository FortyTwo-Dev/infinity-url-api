<?php

use Laravel\Sanctum\Sanctum;

it('revokes only the current refresh family on logout', function () {
    $this->account('logout@example.com');

    $first = $this->loginSession('logout@example.com');
    $second = $this->loginSession('logout@example.com');

    $logout = $this->withRefreshCookie($first->cookie)
        ->postJson('https://api.url.infinityproject.fr/v1/auth/logout');

    $logout->assertStatus(204);

    $this->refreshWith($first->cookie)->assertStatus(401);
    $this->refreshWith($second->cookie)->assertStatus(200);
});

it('revokes every refresh family on logout-all', function () {
    $this->account('logoutall@example.com');

    $first = $this->loginSession('logoutall@example.com');
    $second = $this->loginSession('logoutall@example.com');

    $response = $this->withToken($first->accessToken)
        ->postJson('https://api.url.infinityproject.fr/v1/auth/logout-all');

    $response->assertStatus(204);

    $this->flushHeaders();
    $this->app['auth']->forgetGuards();

    $this->refreshWith($first->cookie)->assertStatus(401);
    $this->refreshWith($second->cookie)->assertStatus(401);
});

it('requires the me write ability to revoke every session', function () {
    [, $user] = $this->account();

    Sanctum::actingAs($user, ['me:read']);

    $response = $this->postJson('https://api.url.infinityproject.fr/v1/auth/logout-all');

    $this->assertProblem($response, 403, 'scope_missing');
});
