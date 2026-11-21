<?php

use App\Models\Domain;
use App\Models\Link;
use App\Models\User;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Database\Seeders\LinkDomainSeeder;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Support\Api;

/**
 * @return array{0: Workspace, 1: User, 2: Domain}
 */
function linkCrudFixture(): array
{
    test()->seed(LinkDomainSeeder::class);

    $workspace = Workspace::factory()->create();
    $user = User::factory()->create();
    $workspace->members()->attach($user->id, ['role' => 'owner']);
    Sanctum::actingAs($user, ['links:read', 'links:write']);

    $domain = Domain::query()->where('hostname', 'iurl.fr')->firstOrFail();

    return [$workspace, $user, $domain];
}

it('creates a link from a destination alone', function () {
    linkCrudFixture();

    $response = $this->postJson(Api::LINKS, [
        'destination' => 'https://example.com/page',
    ]);

    $response->assertCreated()
        ->assertJsonPath('destination', 'https://example.com/page')
        ->assertJsonPath('status', 'active')
        ->assertJsonPath('expires_at', null);

    $slug = $response->json('slug');

    expect($slug)->toMatch('/^[abcdefghijkmnopqrstuvwxyz23456789]{7}$/')
        ->and($response->json('short_url'))->toBe('https://iurl.fr/'.$slug)
        ->and($response->json('id'))->toBeString()->not->toBeEmpty();
});

it('echoes a custom slug and a future expiration', function () {
    linkCrudFixture();

    $expiresAt = now()->addDays(2)->startOfSecond();

    $response = $this->postJson(Api::LINKS, [
        'destination' => 'https://example.com/custom',
        'slug' => '  Custom-Slug ',
        'expires_at' => $expiresAt->toIso8601String(),
    ]);

    $response->assertCreated()
        ->assertJsonPath('slug', 'custom-slug')
        ->assertJsonPath('short_url', 'https://iurl.fr/custom-slug');

    expect(CarbonImmutable::parse($response->json('expires_at'))->getTimestamp())
        ->toBe($expiresAt->getTimestamp());
});

it('rejects an expiration in the past', function () {
    linkCrudFixture();

    $response = $this->postJson(Api::LINKS, [
        'destination' => 'https://example.com/past',
        'expires_at' => now()->subMinute()->toIso8601String(),
    ]);

    $response->assertStatus(422)
        ->assertJsonPath('code', 'validation_failed');

    expect($response->headers->get('Content-Type'))->toContain(Api::PROBLEM_CONTENT_TYPE)
        ->and($response->json('errors.expires_at'))->not->toBeEmpty();
});

dataset('invalid destinations', [
    'javascript scheme' => ['javascript:alert(1)'],
    'userinfo' => ['https://user:pass@example.com/page'],
]);

it('rejects a destination that is not plain http or https', function (string $destination) {
    linkCrudFixture();

    $response = $this->postJson(Api::LINKS, [
        'destination' => $destination,
    ]);

    $response->assertStatus(422)
        ->assertJsonPath('code', 'validation_failed');

    expect($response->headers->get('Content-Type'))->toContain(Api::PROBLEM_CONTENT_TYPE)
        ->and($response->json('errors.destination'))->not->toBeEmpty();
})->with('invalid destinations');

it('refuses link creation for an unverified account', function () {
    linkCrudFixture();

    $unverified = User::factory()->unverified()->create();
    $workspace = Workspace::factory()->create();
    $workspace->members()->attach($unverified->id, ['role' => 'owner']);
    Sanctum::actingAs($unverified, ['links:write']);

    $response = $this->postJson(Api::LINKS, [
        'destination' => 'https://example.com/unverified',
    ]);

    $response->assertStatus(403)
        ->assertJsonPath('code', 'email_unverified');

    expect($response->headers->get('Content-Type'))->toContain(Api::PROBLEM_CONTENT_TYPE);
});

it('lists links newest first and paginates without duplicates', function () {
    [$workspace, , $domain] = linkCrudFixture();

    $oldest = Link::factory()->forDomain($domain)->create([
        'workspace_id' => $workspace->id,
        'slug' => 'oldest01',
        'created_at' => now()->subMinutes(3),
    ]);
    $middle = Link::factory()->forDomain($domain)->create([
        'workspace_id' => $workspace->id,
        'slug' => 'middle01',
        'created_at' => now()->subMinutes(2),
    ]);
    $newest = Link::factory()->forDomain($domain)->create([
        'workspace_id' => $workspace->id,
        'slug' => 'newest01',
        'created_at' => now()->subMinute(),
    ]);

    $response = $this->getJson(Api::LINKS);

    $response->assertOk()
        ->assertJsonPath('has_more', false)
        ->assertJsonPath('next_cursor', null);

    expect(collect($response->json('data'))->pluck('id')->all())
        ->toBe([$newest->public_id, $middle->public_id, $oldest->public_id]);

    $first = $this->getJson(Api::LINKS.'?limit=2');
    $first->assertOk()->assertJsonPath('has_more', true);

    $cursor = $first->json('next_cursor');

    expect($cursor)->toBeString()->not->toBeEmpty();

    $second = $this->getJson(Api::LINKS.'?'.http_build_query([
        'limit' => 2,
        'cursor' => $cursor,
    ]));

    $second->assertOk()->assertJsonPath('has_more', false)->assertJsonPath('next_cursor', null);

    $ids = [
        ...collect($first->json('data'))->pluck('id')->all(),
        ...collect($second->json('data'))->pluck('id')->all(),
    ];

    expect($ids)->toHaveCount(3)
        ->and(array_unique($ids))->toHaveCount(3);
});

it('defaults to twenty items and validates the limit bounds', function () {
    [$workspace, , $domain] = linkCrudFixture();

    for ($i = 0; $i < 21; $i++) {
        Link::factory()->forDomain($domain)->create([
            'workspace_id' => $workspace->id,
            'slug' => 'bulk'.str_pad((string) $i, 3, '0', STR_PAD_LEFT),
        ]);
    }

    $response = $this->getJson(Api::LINKS);
    $response->assertOk();

    expect($response->json('data'))->toHaveCount(20)
        ->and($response->json('has_more'))->toBeTrue();

    $this->getJson(Api::LINKS.'?limit=0')
        ->assertStatus(422)
        ->assertJsonPath('code', 'validation_failed');

    $this->getJson(Api::LINKS.'?limit=101')
        ->assertStatus(422)
        ->assertJsonPath('code', 'validation_failed');
});

it('filters the list by status, domain and query', function () {
    [$workspace, , $domain] = linkCrudFixture();

    Link::factory()->forDomain($domain)->create([
        'workspace_id' => $workspace->id,
        'slug' => 'filteractive',
        'destination' => 'https://example.com/alpha',
    ]);
    Link::factory()->forDomain($domain)->disabled()->create([
        'workspace_id' => $workspace->id,
        'slug' => 'filteroff',
        'destination' => 'https://example.com/bravo',
    ]);
    Link::factory()->forDomain($domain)->create([
        'workspace_id' => $workspace->id,
        'slug' => 'needleslug',
        'destination' => 'https://example.com/charlie',
    ]);
    Link::factory()->forDomain($domain)->create([
        'workspace_id' => $workspace->id,
        'slug' => 'destinationhit',
        'destination' => 'https://example.com/findmehere',
    ]);

    $otherDomain = Domain::factory()->forHost('other.example')->create([
        'workspace_id' => $workspace->id,
    ]);
    Link::factory()->forDomain($otherDomain)->create([
        'workspace_id' => $workspace->id,
        'slug' => 'otherhost1',
        'destination' => 'https://example.com/other',
    ]);

    $slugs = function ($response): array {
        return collect($response->json('data'))->pluck('slug')->all();
    };

    expect($slugs($this->getJson(Api::LINKS.'?status=disabled')->assertOk()))
        ->toBe(['filteroff']);

    $onSystemDomain = $slugs($this->getJson(Api::LINKS.'?domain=iurl.fr')->assertOk());

    expect($onSystemDomain)->toContain('filteractive')
        ->not->toContain('otherhost1');

    expect($slugs($this->getJson(Api::LINKS.'?q=needle')->assertOk()))
        ->toBe(['needleslug']);

    expect($slugs($this->getJson(Api::LINKS.'?q=findmehere')->assertOk()))
        ->toBe(['destinationhit']);

    $response = $this->getJson(Api::LINKS.'?q=n');

    $response->assertStatus(422)
        ->assertJsonPath('code', 'validation_failed');

    expect($response->json('errors.q'))->not->toBeEmpty();
});

it('shows a link by its public id with the exact resource keys', function () {
    [$workspace, , $domain] = linkCrudFixture();

    $link = Link::factory()->forDomain($domain)->create([
        'workspace_id' => $workspace->id,
        'slug' => 'shown123',
        'destination' => 'https://example.com/shown',
        'expires_at' => null,
    ]);

    $response = $this->getJson(Api::LINKS.'/'.$link->public_id);

    $response->assertOk();

    $keys = array_keys($response->json());
    sort($keys);

    expect($keys)->toBe([
        'created_at',
        'destination',
        'expires_at',
        'id',
        'short_url',
        'slug',
        'status',
        'updated_at',
    ]);

    expect($response->json('id'))->toBe($link->public_id)
        ->and($response->json('slug'))->toBe('shown123')
        ->and($response->json('destination'))->toBe('https://example.com/shown')
        ->and($response->json('short_url'))->toBe('https://iurl.fr/shown123')
        ->and($response->json('status'))->toBe('active')
        ->and($response->json('expires_at'))->toBeNull();
});

it('answers link_not_found for an unknown link', function () {
    linkCrudFixture();

    $response = $this->getJson(Api::LINKS.'/'.(string) Str::ulid());

    $response->assertStatus(404)
        ->assertJsonPath('code', 'link_not_found');

    expect($response->headers->get('Content-Type'))->toContain(Api::PROBLEM_CONTENT_TYPE);
});

it('hides a link owned by another workspace', function () {
    [, , $domain] = linkCrudFixture();

    $otherWorkspace = Workspace::factory()->create();
    $foreign = Link::factory()->forDomain($domain)->create([
        'workspace_id' => $otherWorkspace->id,
        'slug' => 'foreign1',
    ]);

    $this->getJson(Api::LINKS.'/'.$foreign->public_id)
        ->assertStatus(404)
        ->assertJsonPath('code', 'link_not_found');
});

it('updates the destination', function () {
    [$workspace, , $domain] = linkCrudFixture();

    $link = Link::factory()->forDomain($domain)->create([
        'workspace_id' => $workspace->id,
        'slug' => 'updated1',
        'destination' => 'https://example.com/before',
    ]);

    $this->patchJson(Api::LINKS.'/'.$link->public_id, [
        'destination' => 'https://example.com/after',
    ])
        ->assertOk()
        ->assertJsonPath('destination', 'https://example.com/after');
});

it('accepts active and disabled as user settable statuses only', function () {
    [$workspace, , $domain] = linkCrudFixture();

    $link = Link::factory()->forDomain($domain)->create([
        'workspace_id' => $workspace->id,
        'slug' => 'status01',
    ]);

    $this->patchJson(Api::LINKS.'/'.$link->public_id, ['status' => 'disabled'])
        ->assertOk()
        ->assertJsonPath('status', 'disabled');

    $response = $this->patchJson(Api::LINKS.'/'.$link->public_id, ['status' => 'blocked']);

    $response->assertStatus(422)
        ->assertJsonPath('code', 'validation_failed');

    expect($response->json('errors.status'))->not->toBeEmpty();
});

it('freezes a blocked link but still allows its deletion', function () {
    [$workspace, , $domain] = linkCrudFixture();

    $link = Link::factory()->forDomain($domain)->blocked()->create([
        'workspace_id' => $workspace->id,
        'slug' => 'blocked1',
    ]);

    $response = $this->patchJson(Api::LINKS.'/'.$link->public_id, [
        'destination' => 'https://example.com/changed',
    ]);

    $response->assertStatus(403)
        ->assertJsonPath('code', 'link_blocked');

    expect($response->headers->get('Content-Type'))->toContain(Api::PROBLEM_CONTENT_TYPE);

    $this->deleteJson(Api::LINKS.'/'.$link->public_id)->assertNoContent();
});

it('deletes a link, which then answers 410 on the link host', function () {
    [$workspace, , $domain] = linkCrudFixture();

    $link = Link::factory()->forDomain($domain)->create([
        'workspace_id' => $workspace->id,
        'slug' => 'deleted1',
    ]);

    $this->deleteJson(Api::LINKS.'/'.$link->public_id)->assertNoContent();

    $this->getJson(Api::LINKS.'/'.$link->public_id)
        ->assertStatus(404)
        ->assertJsonPath('code', 'link_not_found');

    $this->get('https://iurl.fr/deleted1')->assertStatus(410);
});

it('hides write operations on a link owned by another workspace', function () {
    [, , $domain] = linkCrudFixture();

    $otherWorkspace = Workspace::factory()->create();
    $foreign = Link::factory()->forDomain($domain)->create([
        'workspace_id' => $otherWorkspace->id,
        'slug' => 'foreign2',
    ]);

    $this->patchJson(Api::LINKS.'/'.$foreign->public_id, [
        'destination' => 'https://example.com/hijacked',
    ])
        ->assertStatus(404)
        ->assertJsonPath('code', 'link_not_found');

    $this->deleteJson(Api::LINKS.'/'.$foreign->public_id)
        ->assertStatus(404)
        ->assertJsonPath('code', 'link_not_found');
});
