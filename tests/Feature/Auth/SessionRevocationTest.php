<?php

use Laravel\Sanctum\Sanctum;
use Tests\Support\Api;

const LOGOUT_EMAIL = 'logout@example.com';
const LOGOUT_ALL_EMAIL = 'logoutall@example.com';

it('revokes only the current refresh family on logout', function () {
    $this->account(LOGOUT_EMAIL);

    $first = $this->loginSession(LOGOUT_EMAIL);
    $second = $this->loginSession(LOGOUT_EMAIL);

    $logout = $this->withRefreshCookie($first->cookie)
        ->postJson(Api::LOGOUT);

    $logout->assertStatus(204);

    $this->refreshWith($first->cookie)->assertStatus(401);
    $this->refreshWith($second->cookie)->assertStatus(200);
});

it('revokes every refresh family on logout-all', function () {
    $this->account(LOGOUT_ALL_EMAIL);

    $first = $this->loginSession(LOGOUT_ALL_EMAIL);
    $second = $this->loginSession(LOGOUT_ALL_EMAIL);

    $response = $this->withToken($first->accessToken)
        ->postJson(Api::LOGOUT_ALL);

    $response->assertStatus(204);

    $this->flushHeaders();
    $this->app['auth']->forgetGuards();

    $this->refreshWith($first->cookie)->assertStatus(401);
    $this->refreshWith($second->cookie)->assertStatus(401);
});

it('requires the me write ability to revoke every session', function () {
    [, $user] = $this->account();

    Sanctum::actingAs($user, ['me:read']);

    $response = $this->postJson(Api::LOGOUT_ALL);

    $this->assertProblem($response, 403, 'scope_missing');
});
