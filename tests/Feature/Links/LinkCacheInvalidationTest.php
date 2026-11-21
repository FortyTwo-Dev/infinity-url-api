<?php

use App\Enums\LinkStatus;
use App\Models\Domain;
use App\Models\Link;
use App\Models\User;
use App\Models\Workspace;
use Database\Seeders\LinkDomainSeeder;
use Laravel\Sanctum\Sanctum;

const LINK_CACHE_INVALIDATION_API = 'https://api.url.infinityproject.fr';

/**
 * @return array{0: Workspace, 1: Domain}
 */
function linkCacheFixture(): array
{
    test()->seed(LinkDomainSeeder::class);

    $workspace = Workspace::factory()->create();
    $user = User::factory()->create();
    $workspace->members()->attach($user->id, ['role' => 'owner']);
    Sanctum::actingAs($user, ['links:read', 'links:write']);

    $domain = Domain::query()->where('hostname', 'iurl.fr')->firstOrFail();

    return [$workspace, $domain];
}

it('serves the new destination after an update', function () {
    [$workspace, $domain] = linkCacheFixture();

    $link = Link::factory()->forDomain($domain)->create([
        'workspace_id' => $workspace->id,
        'slug' => 'cacheupd1',
        'destination' => 'https://example.com/old',
    ]);

    $this->get('https://iurl.fr/cacheupd1')
        ->assertStatus(302)
        ->assertHeader('Location', 'https://example.com/old');

    $this->patchJson(LINK_CACHE_INVALIDATION_API.'/v1/links/'.$link->public_id, [
        'destination' => 'https://example.com/new',
    ])->assertOk();

    $this->get('https://iurl.fr/cacheupd1')
        ->assertStatus(302)
        ->assertHeader('Location', 'https://example.com/new');
});

it('clears a warmed negative cache entry when the link is created', function () {
    linkCacheFixture();

    $slug = 'cachecreate1';

    $this->get('https://iurl.fr/'.$slug)->assertNotFound();

    $this->postJson(LINK_CACHE_INVALIDATION_API.'/v1/links', [
        'destination' => 'https://example.com/created',
        'slug' => $slug,
    ])->assertCreated();

    $this->get('https://iurl.fr/'.$slug)
        ->assertStatus(302)
        ->assertHeader('Location', 'https://example.com/created');
});

it('invalidates both slugs when a link is renamed', function () {
    [$workspace, $domain] = linkCacheFixture();

    $link = Link::factory()->forDomain($domain)->create([
        'workspace_id' => $workspace->id,
        'slug' => 'cacheold1',
        'destination' => 'https://example.com/renamed',
    ]);

    $this->get('https://iurl.fr/cacheold1')->assertStatus(302);
    $this->get('https://iurl.fr/cachenew1')->assertNotFound();

    $this->patchJson(LINK_CACHE_INVALIDATION_API.'/v1/links/'.$link->public_id, [
        'slug' => 'cachenew1',
    ])->assertOk();

    $this->get('https://iurl.fr/cacheold1')->assertStatus(410);

    $this->get('https://iurl.fr/cachenew1')
        ->assertStatus(302)
        ->assertHeader('Location', 'https://example.com/renamed');
});

it('answers 410 after a warmed link is deleted', function () {
    [$workspace, $domain] = linkCacheFixture();

    $link = Link::factory()->forDomain($domain)->create([
        'workspace_id' => $workspace->id,
        'slug' => 'cachedele1',
    ]);

    $this->get('https://iurl.fr/cachedele1')->assertStatus(302);

    $this->deleteJson(LINK_CACHE_INVALIDATION_API.'/v1/links/'.$link->public_id)
        ->assertNoContent();

    $this->get('https://iurl.fr/cachedele1')->assertStatus(410);
});

it('clears a warmed blocked response when an operator unblocks the link', function () {
    [$workspace, $domain] = linkCacheFixture();

    $link = Link::factory()->forDomain($domain)->blocked()->create([
        'workspace_id' => $workspace->id,
        'slug' => 'cacheblock1',
    ]);

    $this->get('https://iurl.fr/cacheblock1')->assertStatus(403);

    $link->update(['status' => LinkStatus::Active]);

    $this->get('https://iurl.fr/cacheblock1')->assertStatus(302);
});
