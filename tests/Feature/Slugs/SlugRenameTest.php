<?php

use App\Models\Domain;
use App\Models\Link;
use App\Models\User;
use App\Models\Workspace;
use Database\Seeders\LinkDomainSeeder;
use Database\Seeders\ReservedSlugSeeder;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

const SLUG_RENAME_API = 'https://api.url.infinityproject.fr';

/**
 * @return array{0: Workspace, 1: Domain}
 */
function slugRenameFixture(): array
{
    test()->seed(LinkDomainSeeder::class);
    test()->seed(ReservedSlugSeeder::class);

    $workspace = Workspace::factory()->create();
    $user = User::factory()->create();
    $workspace->members()->attach($user->id, ['role' => 'owner']);
    Sanctum::actingAs($user, ['links:read', 'links:write']);

    $domain = Domain::query()->where('hostname', 'iurl.fr')->firstOrFail();

    return [$workspace, $domain];
}

it('renames a link, tombstoning the old slug and redirecting the new one', function () {
    [$workspace, $domain] = slugRenameFixture();

    $link = Link::factory()->forDomain($domain)->create([
        'workspace_id' => $workspace->id,
        'slug' => 'oldslug',
        'destination' => 'https://example.com/renamed',
    ]);

    $this->patchJson(SLUG_RENAME_API.'/v1/links/'.$link->public_id, ['slug' => 'newslug'])
        ->assertOk()
        ->assertJsonPath('slug', 'newslug')
        ->assertJsonPath('short_url', 'https://iurl.fr/newslug');

    $this->get('https://iurl.fr/oldslug')->assertStatus(410);

    $this->get('https://iurl.fr/newslug')
        ->assertStatus(302)
        ->assertHeader('Location', 'https://example.com/renamed');

    expect(DB::table('slug_tombstones')
        ->where('domain_id', $domain->id)
        ->where('slug', 'oldslug')
        ->exists())->toBeTrue();
});

it('refuses a slug already used by another link', function () {
    [$workspace, $domain] = slugRenameFixture();

    $link = Link::factory()->forDomain($domain)->create([
        'workspace_id' => $workspace->id,
        'slug' => 'firstone',
    ]);

    Link::factory()->forDomain($domain)->create([
        'workspace_id' => $workspace->id,
        'slug' => 'secondone',
    ]);

    $response = $this->patchJson(SLUG_RENAME_API.'/v1/links/'.$link->public_id, [
        'slug' => 'secondone',
    ]);

    $response->assertStatus(409)
        ->assertJsonPath('code', 'slug_taken');

    expect($response->headers->get('Content-Type'))->toContain('application/problem+json');

    expect(DB::table('links')->where('id', $link->id)->value('slug'))->toBe('firstone');
});

it('refuses a tombstoned slug', function () {
    [$workspace, $domain] = slugRenameFixture();

    $link = Link::factory()->forDomain($domain)->create([
        'workspace_id' => $workspace->id,
        'slug' => 'liveone',
    ]);

    DB::table('slug_tombstones')->insert([
        'domain_id' => $domain->id,
        'slug' => 'lostslug',
        'erased_at' => now(),
    ]);

    $response = $this->patchJson(SLUG_RENAME_API.'/v1/links/'.$link->public_id, [
        'slug' => 'lostslug',
    ]);

    $response->assertStatus(409)
        ->assertJsonPath('code', 'slug_taken');

    expect(DB::table('links')->where('id', $link->id)->value('slug'))->toBe('liveone');
});

it('refuses a reserved slug on rename', function () {
    [$workspace, $domain] = slugRenameFixture();

    $link = Link::factory()->forDomain($domain)->create([
        'workspace_id' => $workspace->id,
        'slug' => 'liveone',
    ]);

    $response = $this->patchJson(SLUG_RENAME_API.'/v1/links/'.$link->public_id, [
        'slug' => 'docs',
    ]);

    $response->assertStatus(422)
        ->assertJsonPath('code', 'slug_reserved');

    expect($response->headers->get('Content-Type'))->toContain('application/problem+json');
});

it('treats a case variant of the current slug as a no-op', function () {
    [$workspace, $domain] = slugRenameFixture();

    $link = Link::factory()->forDomain($domain)->create([
        'workspace_id' => $workspace->id,
        'slug' => 'casetest',
        'destination' => 'https://example.com/case',
    ]);

    $this->patchJson(SLUG_RENAME_API.'/v1/links/'.$link->public_id, ['slug' => 'CaseTest'])
        ->assertOk()
        ->assertJsonPath('slug', 'casetest');

    expect(DB::table('links')->where('id', $link->id)->value('slug'))->toBe('casetest')
        ->and(DB::table('slug_tombstones')
            ->where('domain_id', $domain->id)
            ->where('slug', 'casetest')
            ->exists())->toBeFalse();

    $this->get('https://iurl.fr/casetest')
        ->assertStatus(302)
        ->assertHeader('Location', 'https://example.com/case');
});
