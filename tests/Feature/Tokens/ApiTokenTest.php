<?php

use App\Models\User;
use App\Models\Workspace;

it('creates an api token, shows the secret once and enforces its scopes', function () {
    $workspace = Workspace::factory()->create();
    $user = User::factory()->create(['email' => 'token@example.com']);
    $workspace->members()->attach($user->id, ['role' => 'owner']);

    $login = $this->postJson('https://api.url.infinityproject.fr/v1/auth/login', [
        'email' => 'token@example.com',
        'password' => 'password',
    ]);
    $login->assertStatus(200);
    $accessToken = $login->json('access_token');

    $response = $this->withToken($accessToken)
        ->postJson('https://api.url.infinityproject.fr/v1/tokens', [
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
        ->getJson('https://api.url.infinityproject.fr/v1/links')
        ->assertStatus(200);

    $forbidden = $this->withToken($secret)
        ->postJson('https://api.url.infinityproject.fr/v1/links', [
            'destination' => 'https://example.com/from-a-read-only-token',
        ]);

    $forbidden->assertStatus(403);
    expect((string) $forbidden->headers->get('Content-Type'))->toStartWith('application/problem+json');
    expect($forbidden->json('code'))->toBe('scope_missing');
});

it('never returns the secret from the token list and hides dashboard sessions', function () {
    $workspace = Workspace::factory()->create();
    $user = User::factory()->create(['email' => 'list@example.com']);
    $workspace->members()->attach($user->id, ['role' => 'owner']);

    $login = $this->postJson('https://api.url.infinityproject.fr/v1/auth/login', [
        'email' => 'list@example.com',
        'password' => 'password',
    ]);
    $login->assertStatus(200);
    $accessToken = $login->json('access_token');

    $this->withToken($accessToken)
        ->postJson('https://api.url.infinityproject.fr/v1/tokens', [
            'name' => 'iQuiz',
            'scopes' => ['links:read', 'links:write'],
        ])
        ->assertStatus(201);

    $this->app['auth']->forgetGuards();

    $response = $this->withToken($accessToken)->getJson('https://api.url.infinityproject.fr/v1/tokens');

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
    $workspace = Workspace::factory()->create();
    $user = User::factory()->create(['email' => 'scopes@example.com']);
    $workspace->members()->attach($user->id, ['role' => 'owner']);

    $login = $this->postJson('https://api.url.infinityproject.fr/v1/auth/login', [
        'email' => 'scopes@example.com',
        'password' => 'password',
    ]);
    $login->assertStatus(200);
    $accessToken = $login->json('access_token');

    foreach ([['*'], ['me:write'], ['unknown:read']] as $scopes) {
        $response = $this->withToken($accessToken)
            ->postJson('https://api.url.infinityproject.fr/v1/tokens', [
                'name' => 'rejected',
                'scopes' => $scopes,
            ]);

        $response->assertStatus(422);
        expect((string) $response->headers->get('Content-Type'))->toStartWith('application/problem+json');
        expect($response->json('code'))->toBe('validation_failed');
        expect($response->json('errors'))->toHaveKey('scopes');
    }
});

it('refuses to grant a scope the minting token does not hold', function () {
    $workspace = Workspace::factory()->create();
    $user = User::factory()->create(['email' => 'escalate@example.com']);
    $workspace->members()->attach($user->id, ['role' => 'owner']);

    $login = $this->postJson('https://api.url.infinityproject.fr/v1/auth/login', [
        'email' => 'escalate@example.com',
        'password' => 'password',
    ]);
    $login->assertStatus(200);
    $accessToken = $login->json('access_token');

    $lower = $this->withToken($accessToken)
        ->postJson('https://api.url.infinityproject.fr/v1/tokens', [
            'name' => 'iQuiz',
            'scopes' => ['links:read', 'tokens:write'],
        ]);
    $lower->assertStatus(201);

    $this->app['auth']->forgetGuards();

    $attempt = $this->withToken($lower->json('token'))
        ->postJson('https://api.url.infinityproject.fr/v1/tokens', [
            'name' => 'escalated',
            'scopes' => ['links:write'],
        ]);

    expect($attempt->status())->toBeIn([422, 403]);

    $this->app['auth']->forgetGuards();

    $dashboard = $this->withToken($accessToken)
        ->postJson('https://api.url.infinityproject.fr/v1/tokens', [
            'name' => 'writer',
            'scopes' => ['links:write'],
        ]);

    $dashboard->assertStatus(201);
    expect($dashboard->json('scopes'))->toBe(['links:write']);
});

it('revokes a token and answers 404 for another users token', function () {
    $workspace = Workspace::factory()->create();
    $user = User::factory()->create(['email' => 'revoke@example.com']);
    $workspace->members()->attach($user->id, ['role' => 'owner']);

    $otherWorkspace = Workspace::factory()->create();
    $other = User::factory()->create(['email' => 'other@example.com']);
    $otherWorkspace->members()->attach($other->id, ['role' => 'owner']);

    $login = $this->postJson('https://api.url.infinityproject.fr/v1/auth/login', [
        'email' => 'revoke@example.com',
        'password' => 'password',
    ]);
    $login->assertStatus(200);
    $accessToken = $login->json('access_token');

    $created = $this->withToken($accessToken)
        ->postJson('https://api.url.infinityproject.fr/v1/tokens', [
            'name' => 'revoked',
            'scopes' => ['links:read'],
        ]);
    $created->assertStatus(201);

    $id = $created->json('id');
    $secret = $created->json('token');

    $this->withToken($accessToken)
        ->deleteJson('https://api.url.infinityproject.fr/v1/tokens/'.$id)
        ->assertStatus(204);

    $this->app['auth']->forgetGuards();

    expect($this->withToken($secret)->getJson('https://api.url.infinityproject.fr/v1/links')->status())->toBe(401);

    $foreign = $other->createToken('foreign', ['links:read'])->accessToken;

    $this->app['auth']->forgetGuards();

    $response = $this->withToken($accessToken)
        ->deleteJson('https://api.url.infinityproject.fr/v1/tokens/'.$foreign->id);

    $response->assertStatus(404);
    expect((string) $response->headers->get('Content-Type'))->toStartWith('application/problem+json');
    expect($response->json('code'))->toBe('not_found');
});

it('requires the tokens read ability to list tokens', function () {
    $workspace = Workspace::factory()->create();
    $user = User::factory()->create(['email' => 'noread@example.com']);
    $workspace->members()->attach($user->id, ['role' => 'owner']);

    $login = $this->postJson('https://api.url.infinityproject.fr/v1/auth/login', [
        'email' => 'noread@example.com',
        'password' => 'password',
    ]);
    $login->assertStatus(200);

    $created = $this->withToken($login->json('access_token'))
        ->postJson('https://api.url.infinityproject.fr/v1/tokens', [
            'name' => 'no-token-read',
            'scopes' => ['links:read'],
        ]);
    $created->assertStatus(201);

    $this->app['auth']->forgetGuards();

    $response = $this->withToken($created->json('token'))
        ->getJson('https://api.url.infinityproject.fr/v1/tokens');

    $response->assertStatus(403);
    expect((string) $response->headers->get('Content-Type'))->toStartWith('application/problem+json');
    expect($response->json('code'))->toBe('scope_missing');
});
