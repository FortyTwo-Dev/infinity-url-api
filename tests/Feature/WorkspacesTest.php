<?php

use App\Models\User;
use App\Models\Workspace;
use Laravel\Sanctum\Sanctum;

it('returns exactly one workspace in a cursor envelope', function () {
    $workspace = Workspace::factory()->create(['name' => 'My workspace']);
    $user = User::factory()->create();
    $workspace->members()->attach($user->id, ['role' => 'owner']);

    Sanctum::actingAs($user, ['me:read']);

    $response = $this->getJson('https://api.url.infinityproject.fr/v1/workspaces');

    $response->assertStatus(200);

    $body = $response->json();
    expect(array_keys($body))->toEqualCanonicalizing(['data', 'next_cursor', 'has_more']);
    expect($body['next_cursor'])->toBeNull();
    expect($body['has_more'])->toBeFalse();

    expect($body['data'])->toHaveCount(1);
    expect($body['data'][0]['id'])->toBe($workspace->public_id);
    expect($body['data'][0]['name'])->toBe('My workspace');
});

it('answers 401 without a token', function () {
    $response = $this->getJson('https://api.url.infinityproject.fr/v1/workspaces');

    $response->assertStatus(401);
    expect((string) $response->headers->get('Content-Type'))->toStartWith('application/problem+json');
    expect($response->json('code'))->toBe('unauthorized');
});

it('answers 403 when the token lacks the me read ability', function () {
    $workspace = Workspace::factory()->create();
    $user = User::factory()->create();
    $workspace->members()->attach($user->id, ['role' => 'owner']);

    Sanctum::actingAs($user, ['links:read']);

    $response = $this->getJson('https://api.url.infinityproject.fr/v1/workspaces');

    $response->assertStatus(403);
    expect((string) $response->headers->get('Content-Type'))->toStartWith('application/problem+json');
    expect($response->json('code'))->toBe('scope_missing');
});
