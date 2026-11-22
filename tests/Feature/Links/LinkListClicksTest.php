<?php

use App\Models\Link;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Support\AnalyticsHelpers;
use Tests\Support\Api;

uses(AnalyticsHelpers::class);

const LINK_LIST_CLICKS_FROZEN_AT = '2026-11-22 10:00:00';
const LINK_LIST_CLICKS_QUERY = '?include=clicks_7d';
const LINK_LIST_CLICKS_DAY = '2026-11-22';

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse(LINK_LIST_CLICKS_FROZEN_AT, 'UTC'));
});

it('keeps the list payload free of clicks_7d without the include parameter', function () {
    [$workspace, $user] = $this->account();
    $domain = $this->linkDomain();
    Sanctum::actingAs($user, ['links:read']);

    foreach (['plain001', 'plain002', 'plain003'] as $slug) {
        $this->linkOn($workspace, $domain, $slug);
    }

    $response = $this->getJson(Api::LINKS);

    $response->assertOk()->assertJsonPath('has_more', false);

    $items = $response->json('data');

    expect($items)->toHaveCount(3);

    foreach ($items as $item) {
        expect($this->sortedKeys($item))->toBe([
            'created_at',
            'destination',
            'expires_at',
            'id',
            'short_url',
            'slug',
            'status',
            'updated_at',
        ]);
        expect($item)->not->toHaveKey('clicks_7d');
    }
});

it('adds an integer clicks_7d to every item when asked', function () {
    [$workspace, $user] = $this->account();
    $domain = $this->linkDomain();
    Sanctum::actingAs($user, ['links:read']);

    $counted = $this->linkOn($workspace, $domain, 'counted1');
    $this->linkOn($workspace, $domain, 'counted2');
    $this->seedDailyStats($counted->id, '2026-11-21', 4, 2);

    $response = $this->getJson(Api::LINKS.LINK_LIST_CLICKS_QUERY);

    $response->assertOk();

    $items = collect($response->json('data'))->keyBy('slug');

    expect($items)->toHaveCount(2);

    foreach ($items as $item) {
        expect($item)->toHaveKey('clicks_7d');
        expect($item['clicks_7d'])->toBeInt();
    }

    expect($items['counted1']['clicks_7d'])->toBe(4)
        ->and($items['counted2']['clicks_7d'])->toBe(0);
});

it('sums the seven UTC days ending today and excludes older rows', function () {
    [$workspace, $user] = $this->account();
    $domain = $this->linkDomain();
    Sanctum::actingAs($user, ['links:read']);

    $link = $this->linkOn($workspace, $domain, 'windowed');

    $this->seedDailyStats($link->id, '2026-11-15', 100, 50);
    $this->seedDailyStats($link->id, '2026-11-16', 2, 1);
    $this->seedDailyStats($link->id, LINK_LIST_CLICKS_DAY, 3, 2);

    $response = $this->getJson(Api::LINKS.LINK_LIST_CLICKS_QUERY);

    $response->assertOk();

    $items = $response->json('data');

    expect($items)->toHaveCount(1);
    expect($items[0]['id'])->toBe($link->public_id);
    expect($items[0])->toHaveKey('clicks_7d');
    expect($items[0]['clicks_7d'])->toBe(5);
});

it('answers zero for a link without daily counters', function () {
    [$workspace, $user] = $this->account();
    $domain = $this->linkDomain();
    Sanctum::actingAs($user, ['links:read']);

    $link = $this->linkOn($workspace, $domain, 'noclicks');

    $response = $this->getJson(Api::LINKS.LINK_LIST_CLICKS_QUERY);

    $response->assertOk();

    $items = $response->json('data');

    expect($items)->toHaveCount(1)
        ->and($items[0]['id'])->toBe($link->public_id)
        ->and($items[0])->toHaveKey('clicks_7d')
        ->and($items[0]['clicks_7d'])->toBe(0);
});

it('counts only the links of the active workspace', function () {
    [$workspace, $user] = $this->account();
    $domain = $this->linkDomain();
    Sanctum::actingAs($user, ['links:read']);

    $mine = $this->linkOn($workspace, $domain, 'mine0001');
    $this->seedDailyStats($mine->id, '2026-11-20', 2, 1);

    $otherWorkspace = Workspace::factory()->create();
    $foreign = $this->linkOn($otherWorkspace, $domain, 'foreign1');
    $this->seedDailyStats($foreign->id, '2026-11-20', 100, 50);

    $response = $this->getJson(Api::LINKS.LINK_LIST_CLICKS_QUERY);

    $response->assertOk();

    $items = $response->json('data');

    expect($items)->toHaveCount(1);
    expect($items[0]['id'])->toBe($mine->public_id);
    expect($items[0])->toHaveKey('clicks_7d');
    expect($items[0]['clicks_7d'])->toBe(2);
});

it('reads the page counters in a single aggregated query', function () {
    [$workspace, $user] = $this->account();
    $domain = $this->linkDomain();
    Sanctum::actingAs($user, ['links:read']);

    foreach (['bulk0001', 'bulk0002', 'bulk0003'] as $slug) {
        $link = $this->linkOn($workspace, $domain, $slug);
        $this->seedDailyStats($link->id, LINK_LIST_CLICKS_DAY, 1, 1);
    }

    $statements = [];

    DB::listen(function (QueryExecuted $query) use (&$statements): void {
        if (str_contains($query->sql, 'link_stats_daily')) {
            $statements[] = $query->sql;
        }
    });

    $this->getJson(Api::LINKS.LINK_LIST_CLICKS_QUERY)->assertOk();

    expect($statements)->toHaveCount(1);
});

it('rejects an unknown include value', function () {
    [, $user] = $this->account();
    Sanctum::actingAs($user, ['links:read']);

    $response = $this->getJson(Api::LINKS.'?include=unknown');

    $this->assertProblem($response, 422, 'validation_failed');

    expect($response->json('errors.include'))->not->toBeEmpty();
});

it('keeps the query filter and the cursor pagination with the include', function () {
    [$workspace, $user] = $this->account();
    $domain = $this->linkDomain();
    Sanctum::actingAs($user, ['links:read']);

    $newest = Link::factory()->forDomain($domain)->create([
        'workspace_id' => $workspace->id,
        'slug' => 'paginate1',
        'destination' => 'https://example.com/paginate-newest',
        'created_at' => now()->subMinute(),
    ]);
    $oldest = Link::factory()->forDomain($domain)->create([
        'workspace_id' => $workspace->id,
        'slug' => 'paginate2',
        'destination' => 'https://example.com/paginate-oldest',
        'created_at' => now()->subMinutes(2),
    ]);
    Link::factory()->forDomain($domain)->create([
        'workspace_id' => $workspace->id,
        'slug' => 'unrelated',
        'destination' => 'https://example.com/something-else',
        'created_at' => now()->subMinutes(3),
    ]);

    $this->seedDailyStats($newest->id, LINK_LIST_CLICKS_DAY, 1, 1);
    $this->seedDailyStats($oldest->id, LINK_LIST_CLICKS_DAY, 2, 1);

    $first = $this->getJson(Api::LINKS.'?'.http_build_query([
        'include' => 'clicks_7d',
        'q' => 'paginate',
        'limit' => 1,
    ]));

    $first->assertOk()->assertJsonPath('has_more', true);

    $cursor = $first->json('next_cursor');

    expect($cursor)->toBeString()->not->toBeEmpty();

    $second = $this->getJson(Api::LINKS.'?'.http_build_query([
        'include' => 'clicks_7d',
        'q' => 'paginate',
        'limit' => 1,
        'cursor' => $cursor,
    ]));

    $second->assertOk()
        ->assertJsonPath('has_more', false)
        ->assertJsonPath('next_cursor', null);

    $firstItems = $first->json('data');
    $secondItems = $second->json('data');

    expect($firstItems)->toHaveCount(1);
    expect($secondItems)->toHaveCount(1);
    expect($firstItems[0]['id'])->toBe($newest->public_id);
    expect($secondItems[0]['id'])->toBe($oldest->public_id);

    $ids = [...array_column($firstItems, 'id'), ...array_column($secondItems, 'id')];

    expect(array_unique($ids))->toHaveCount(2);

    expect($firstItems[0])->toHaveKey('clicks_7d');
    expect($firstItems[0]['clicks_7d'])->toBe(1);
    expect($secondItems[0])->toHaveKey('clicks_7d');
    expect($secondItems[0]['clicks_7d'])->toBe(2);
});

it('requires a token to list links with counters', function () {
    $this->assertProblem(
        $this->getJson(Api::LINKS.LINK_LIST_CLICKS_QUERY),
        401,
        'unauthorized',
    );
});

it('requires the links read scope to request counters', function () {
    [, $user] = $this->account();
    Sanctum::actingAs($user, ['links:write']);

    $this->assertProblem(
        $this->getJson(Api::LINKS.LINK_LIST_CLICKS_QUERY),
        403,
        'scope_missing',
    );
});
