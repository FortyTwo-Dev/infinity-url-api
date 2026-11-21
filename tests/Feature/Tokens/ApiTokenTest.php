<?php

use Tests\Support\Api;

it('creates an api token, shows the secret once and enforces its scopes', function () {
    $this->account('token@example.com');

    $accessToken = $this->loginSession('token@example.com')->accessToken;

    $response = $this->withToken($accessToken)
        ->postJson(Api::TOKENS, [
            'name' => 'iQuiz',
            'scopes' => ['links:read'],
        ]);

    $response->assertStatus(201);

    $created = $response->json();
    expect(array_keys($created))->toEqualCanonicalizing([
        'id', 'name', 'scopes', 'last_used_at', 'expires_at', 'created_at', 'token',
    ]);
    expect($created['id'])->toBeInt();
    expect($created['name'])->toBe('iQuiz');
    expect($created['scopes'])->toBe(['links:read']);
    expect($created['last_used_at'])->toBeNull();
    expect($created['expires_at'])->toBeNull();
    expect($created['token'])->toBeString()->toContain('|');
    expect(str_starts_with($created['token'], $created['id'].'|'))->toBeTrue();

    $secret = $created['token'];

    $this->app['auth']->forgetGuards();

    $this->withToken($secret)
        ->getJson(Api::LINKS)
        ->assertStatus(200);

    $forbidden = $this->withToken($secret)
        ->postJson(Api::LINKS, [
            'destination' => 'https://example.com/from-a-read-only-token',
        ]);

    $this->assertProblem($forbidden, 403, 'scope_missing');
});

it('never returns the secret from the token list and hides dashboard sessions', function () {
    $this->account('list@example.com');

    $accessToken = $this->loginSession('list@example.com')->accessToken;

    $this->withToken($accessToken)
        ->postJson(Api::TOKENS, [
            'name' => 'iQuiz',
            'scopes' => ['links:read', 'links:write'],
        ])
        ->assertStatus(201);

    $this->app['auth']->forgetGuards();

    $response = $this->withToken($accessToken)->getJson(Api::TOKENS);

    $response->assertStatus(200);
    expect(array_keys($response->json()))->toEqualCanonicalizing(['data', 'next_cursor', 'has_more']);
    expect($response->json('next_cursor'))->toBeNull();
    expect($response->json('has_more'))->toBeFalse();

    $items = $response->json('data');
    expect($items)->toHaveCount(1);
    expect(array_keys($items[0]))->toEqualCanonicalizing([
        'id', 'name', 'scopes', 'last_used_at', 'expires_at', 'created_at',
    ]);
    expect($items[0])->not->toHaveKey('token');
    expect($items[0]['name'])->toBe('iQuiz');
    expect($items[0]['scopes'])->toBe(['links:read', 'links:write']);
});

it('rejects wildcard, me write and unknown scopes', function () {
    $this->account('scopes@example.com');

    $accessToken = $this->loginSession('scopes@example.com')->accessToken;

    foreach ([['*'], ['me:write'], ['unknown:read']] as $scopes) {
        $response = $this->withToken($accessToken)
            ->postJson(Api::TOKENS, [
                'name' => 'rejected',
                'scopes' => $scopes,
            ]);

        $this->assertProblem($response, 422, 'validation_failed');
        expect($response->json('errors'))->toHaveKey('scopes');
    }
});

it('refuses to grant a scope the minting token does not hold', function () {
    $this->account('escalate@example.com');

    $accessToken = $this->loginSession('escalate@example.com')->accessToken;

    $lower = $this->withToken($accessToken)
        ->postJson(Api::TOKENS, [
            'name' => 'iQuiz',
            'scopes' => ['links:read', 'tokens:write'],
        ]);
    $lower->assertStatus(201);

    $this->app['auth']->forgetGuards();

    $attempt = $this->withToken($lower->json('token'))
        ->postJson(Api::TOKENS, [
            'name' => 'escalated',
            'scopes' => ['links:write'],
        ]);

    expect($attempt->status())->toBeIn([422, 403]);

    $this->app['auth']->forgetGuards();

    $dashboard = $this->withToken($accessToken)
        ->postJson(Api::TOKENS, [
            'name' => 'writer',
            'scopes' => ['links:write'],
        ]);

    $dashboard->assertStatus(201);
    expect($dashboard->json('scopes'))->toBe(['links:write']);
});

it('revokes a token and answers 404 for another users token', function () {
    $this->account('revoke@example.com');
    [, $other] = $this->account('other@example.com');

    $accessToken = $this->loginSession('revoke@example.com')->accessToken;

    $created = $this->withToken($accessToken)
        ->postJson(Api::TOKENS, [
            'name' => 'revoked',
            'scopes' => ['links:read'],
        ]);
    $created->assertStatus(201);

    $id = $created->json('id');
    $secret = $created->json('token');

    $this->withToken($accessToken)
        ->deleteJson(Api::TOKENS.'/'.$id)
        ->assertStatus(204);

    $this->app['auth']->forgetGuards();

    expect($this->withToken($secret)->getJson(Api::LINKS)->status())->toBe(401);

    $foreign = $other->createToken('foreign', ['links:read'])->accessToken;

    $this->app['auth']->forgetGuards();

    $response = $this->withToken($accessToken)
        ->deleteJson(Api::TOKENS.'/'.$foreign->id);

    $this->assertProblem($response, 404, 'not_found');
});

it('requires the tokens read ability to list tokens', function () {
    $this->account('noread@example.com');

    $accessToken = $this->loginSession('noread@example.com')->accessToken;

    $created = $this->withToken($accessToken)
        ->postJson(Api::TOKENS, [
            'name' => 'no-token-read',
            'scopes' => ['links:read'],
        ]);
    $created->assertStatus(201);

    $this->app['auth']->forgetGuards();

    $response = $this->withToken($created->json('token'))
        ->getJson(Api::TOKENS);

    $this->assertProblem($response, 403, 'scope_missing');
});
