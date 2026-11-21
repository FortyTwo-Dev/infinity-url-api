<?php

use App\Models\User;
use App\Models\Workspace;
use App\Services\ReservedSlugs;
use Database\Seeders\LinkDomainSeeder;
use Database\Seeders\ReservedSlugSeeder;
use Laravel\Sanctum\Sanctum;

const SLUG_RESERVATION_API = 'https://api.url.infinityproject.fr';

function slugReservationWorkspace(): Workspace
{
    test()->seed(LinkDomainSeeder::class);
    test()->seed(ReservedSlugSeeder::class);

    $workspace = Workspace::factory()->create();
    $user = User::factory()->create();
    $workspace->members()->attach($user->id, ['role' => 'owner']);
    Sanctum::actingAs($user, ['links:write']);

    return $workspace;
}

it('knows which slugs are reserved', function () {
    $this->seed(ReservedSlugSeeder::class);

    $reserved = app(ReservedSlugs::class);

    expect($reserved->exists('docs'))->toBeTrue()
        ->and($reserved->exists('horizon'))->toBeTrue()
        ->and($reserved->exists('v1'))->toBeTrue()
        ->and($reserved->exists('not-reserved-xyz'))->toBeFalse();
});

dataset('reserved slug attempts', [
    'docs' => ['docs'],
    'horizon' => ['horizon'],
    'v1' => ['v1'],
]);

it('refuses a reserved slug even when no link uses it', function (string $slug) {
    slugReservationWorkspace();

    $response = $this->postJson(SLUG_RESERVATION_API.'/v1/links', [
        'destination' => 'https://example.com/reserved',
        'slug' => $slug,
    ]);

    $response->assertStatus(422)
        ->assertJsonPath('code', 'slug_reserved');

    expect($response->headers->get('Content-Type'))->toContain('application/problem+json')
        ->and($response->json('errors.slug'))->not->toBeEmpty();
})->with('reserved slug attempts');
