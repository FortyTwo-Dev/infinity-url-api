<?php

use App\Enums\LinkStatus;
use App\Models\Domain;
use App\Models\Link;
use App\Models\User;
use App\Models\Workspace;
use Database\Seeders\LinkDomainSeeder;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Support\Api;

/**
 * @return array{0: Workspace, 1: User, 2: Domain}
 */
function linkQuotaFixture(): array
{
    test()->seed(LinkDomainSeeder::class);

    $workspace = Workspace::factory()->create();
    $user = User::factory()->create();
    $workspace->members()->attach($user->id, ['role' => 'owner']);
    Sanctum::actingAs($user, ['links:read', 'links:write']);

    $domain = Domain::query()->where('hostname', 'iurl.fr')->firstOrFail();

    return [$workspace, $user, $domain];
}

/**
 * @return array<int, Link>
 */
function linkQuotaFill(Workspace $workspace, Domain $domain, int $count, array $overrides = []): array
{
    $links = [];

    for ($i = 1; $i <= $count; $i++) {
        $links[] = Link::factory()->forDomain($domain)->create([
            'workspace_id' => $workspace->id,
            'slug' => 'quota'.str_pad((string) $i, 3, '0', STR_PAD_LEFT),
            ...$overrides,
        ]);
    }

    return $links;
}

it('refuses a sixth active link', function () {
    [$workspace, , $domain] = linkQuotaFixture();

    linkQuotaFill($workspace, $domain, 5);

    $response = $this->postJson(Api::LINKS, [
        'destination' => 'https://example.com/sixth',
    ]);

    $response->assertStatus(409)
        ->assertJsonPath('code', 'quota_exceeded');

    expect($response->headers->get('Content-Type'))->toContain(Api::PROBLEM_CONTENT_TYPE)
        ->and(DB::table('links')->where('workspace_id', $workspace->id)->count())->toBe(5);
});

it('frees a slot when a link is disabled', function () {
    [$workspace, , $domain] = linkQuotaFixture();

    $links = linkQuotaFill($workspace, $domain, 5);

    $this->patchJson(Api::LINKS.'/'.$links[0]->public_id, ['status' => 'disabled'])
        ->assertOk()
        ->assertJsonPath('status', 'disabled');

    $this->postJson(Api::LINKS, [
        'destination' => 'https://example.com/replacement',
    ])->assertCreated();
});

it('requires a free slot and a future expiration to reactivate a disabled link', function () {
    [$workspace, , $domain] = linkQuotaFixture();

    $links = linkQuotaFill($workspace, $domain, 5);
    $disabled = $links[0];
    $other = $links[1];

    $this->patchJson(Api::LINKS.'/'.$disabled->public_id, ['status' => 'disabled'])
        ->assertOk();

    $this->postJson(Api::LINKS, [
        'destination' => 'https://example.com/refill',
    ])->assertCreated();

    $response = $this->patchJson(Api::LINKS.'/'.$disabled->public_id, [
        'status' => 'active',
        'expires_at' => now()->addDay()->toIso8601String(),
    ]);

    $response->assertStatus(409)
        ->assertJsonPath('code', 'quota_exceeded');

    $this->patchJson(Api::LINKS.'/'.$other->public_id, ['status' => 'disabled'])
        ->assertOk();

    $this->patchJson(Api::LINKS.'/'.$disabled->public_id, [
        'status' => 'active',
        'expires_at' => now()->addDay()->toIso8601String(),
    ])
        ->assertOk()
        ->assertJsonPath('status', 'active');
});

it('does not count an expired link against the quota', function () {
    [$workspace, , $domain] = linkQuotaFixture();

    linkQuotaFill($workspace, $domain, 4);

    Link::factory()->forDomain($domain)->create([
        'workspace_id' => $workspace->id,
        'slug' => 'expiredq1',
        'status' => LinkStatus::Active,
        'expires_at' => now()->subMinute(),
    ]);

    $this->postJson(Api::LINKS, [
        'destination' => 'https://example.com/after-expiry',
    ])->assertCreated();
});

it('keeps the link count unchanged when a creation is refused', function () {
    [$workspace, , $domain] = linkQuotaFixture();

    linkQuotaFill($workspace, $domain, 5);

    $before = DB::table('links')->where('workspace_id', $workspace->id)->count();

    $this->postJson(Api::LINKS, [
        'destination' => 'javascript:alert(1)',
    ])->assertStatus(422);

    expect(DB::table('links')->where('workspace_id', $workspace->id)->count())->toBe($before);

    $this->postJson(Api::LINKS, [
        'destination' => 'https://example.com/refused',
    ])->assertStatus(409);

    expect(DB::table('links')->where('workspace_id', $workspace->id)->count())->toBe($before);
});
