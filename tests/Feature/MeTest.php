<?php

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

it('returns the account, its workspace, its limits and its capabilities', function () {
    $workspace = Workspace::factory()->create(['name' => 'My workspace']);
    $user = User::factory()->create(['email' => 'me@example.com']);
    $workspace->members()->attach($user->id, ['role' => 'owner']);

    Sanctum::actingAs($user, ['me:read']);

    $response = $this->getJson('https://api.url.infinityproject.fr/v1/me');

    $response->assertStatus(200);

    $body = $response->json();
    expect(array_keys($body))->toEqualCanonicalizing(['user', 'workspace', 'capabilities']);

    expect(array_keys($body['user']))->toEqualCanonicalizing(['id', 'email', 'email_verified', 'totp_enabled']);
    expect(Str::isUlid($body['user']['id']))->toBeTrue();
    expect($body['user']['id'])->toBe($user->public_id);
    expect($body['user']['email'])->toBe('me@example.com');
    expect($body['user']['email_verified'])->toBeTrue();
    expect($body['user']['totp_enabled'])->toBeFalse();

    expect(array_keys($body['workspace']))->toEqualCanonicalizing(['id', 'name', 'plan', 'limits']);
    expect($body['workspace']['id'])->toBe($workspace->public_id);
    expect($body['workspace']['name'])->toBe('My workspace');
    expect($body['workspace']['plan'])->toBe('free');

    expect(array_keys($body['workspace']['limits']))->toEqualCanonicalizing([
        'active_links', 'custom_domains', 'stats_retention_days',
    ]);
    expect($body['workspace']['limits']['active_links'])->toBe(5);
    expect($body['workspace']['limits']['custom_domains'])->toBe(0);
    expect($body['workspace']['limits']['stats_retention_days'])
        ->toBe((int) config('identity.limits.stats_retention_days', 30));

    expect($body['capabilities'])->toEqualCanonicalizing([
        'links:read', 'links:write', 'stats:read', 'tokens:read', 'tokens:write', 'qr',
    ]);

    $response->assertJsonMissingPath('user.email_hash');
    $response->assertJsonMissingPath('user.password_hash');
    $response->assertJsonMissingPath('user.totp_secret');
    expect($body['user'])->not->toHaveKey('email_hash');
    expect($body['user'])->not->toHaveKey('password_hash');
    expect($body['user'])->not->toHaveKey('totp_secret');
});

it('answers 401 without a token', function () {
    $response = $this->getJson('https://api.url.infinityproject.fr/v1/me');

    $response->assertStatus(401);
    expect((string) $response->headers->get('Content-Type'))->toStartWith('application/problem+json');
    expect($response->json('code'))->toBe('unauthorized');
    expect($response->json('instance'))->toBe('/v1/me');
});

it('answers 403 when the token lacks the me read ability', function () {
    $workspace = Workspace::factory()->create();
    $user = User::factory()->create();
    $workspace->members()->attach($user->id, ['role' => 'owner']);

    Sanctum::actingAs($user, ['links:read']);

    $response = $this->getJson('https://api.url.infinityproject.fr/v1/me');

    $response->assertStatus(403);
    expect((string) $response->headers->get('Content-Type'))->toStartWith('application/problem+json');
    expect($response->json('code'))->toBe('scope_missing');
    expect($response->json('instance'))->toBe('/v1/me');
});
