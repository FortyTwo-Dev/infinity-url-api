<?php

use App\Models\Domain;
use App\Models\Link;
use App\Models\User;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Database\Seeders\LinkDomainSeeder;
use Laravel\Sanctum\Sanctum;
use Tests\Support\AnalyticsHelpers;

uses(AnalyticsHelpers::class);

const PERSONAL_DATA_EXPORT_API = 'https://api.url.infinityproject.fr';
const EXPORT_PATH = '/v1/me/export';
const EXPORT_STATS_DAY = '2026-11-20';

/**
 * @param  array<string, mixed>  $value
 * @return array<int, string>
 */
function personalDataExportSortedKeys(array $value): array
{
    $keys = array_keys($value);
    sort($keys);

    return $keys;
}

/**
 * @return array<int, string>
 */
function personalDataExportJsonKeys(mixed $value): array
{
    if (! is_array($value)) {
        return [];
    }

    $keys = [];

    foreach ($value as $key => $item) {
        if (is_string($key)) {
            $keys[] = $key;
        }

        $keys = [...$keys, ...personalDataExportJsonKeys($item)];
    }

    return $keys;
}

it('exports exactly the allowlisted personal data', function () {
    $this->seed(LinkDomainSeeder::class);

    $workspace = Workspace::factory()->create(['name' => 'My workspace']);
    $user = User::factory()->create(['email' => 'owner@example.com']);
    $workspace->members()->attach($user->id, ['role' => 'owner']);
    Sanctum::actingAs($user, ['me:read']);

    $domain = Domain::query()->where('hostname', 'iurl.fr')->firstOrFail();
    $link = Link::factory()->forDomain($domain)->create([
        'workspace_id' => $workspace->id,
        'slug' => 'export1',
        'destination' => 'https://example.com/export',
        'expires_at' => null,
    ]);

    $response = $this->getJson(PERSONAL_DATA_EXPORT_API.EXPORT_PATH);
    $response->assertOk();

    $body = $response->json();

    expect(personalDataExportSortedKeys($body))
        ->toBe(['account', 'links', 'statistics', 'workspace']);

    expect(personalDataExportSortedKeys($body['account']))
        ->toBe(['created_at', 'email', 'email_verified', 'id', 'totp_enabled']);

    expect(personalDataExportSortedKeys($body['workspace']))
        ->toBe(['id', 'limits', 'name', 'plan']);

    expect(personalDataExportSortedKeys($body['workspace']['limits']))
        ->toBe(['active_links', 'custom_domains', 'stats_retention_days']);

    expect($body['statistics'])->toBe([]);

    expect($body['account']['id'])->toBe($user->public_id)
        ->and($body['account']['email'])->toBe('owner@example.com')
        ->and($body['account']['email_verified'])->toBeTrue()
        ->and($body['account']['totp_enabled'])->toBeFalse();

    expect($body['workspace']['id'])->toBe($workspace->public_id)
        ->and($body['workspace']['name'])->toBe('My workspace')
        ->and($body['workspace']['plan'])->toBe('free')
        ->and($body['workspace']['limits']['active_links'])->toBe(5)
        ->and($body['workspace']['limits']['custom_domains'])->toBe(0);

    expect($body['links'])->toHaveCount(1);

    $item = $body['links'][0];

    expect(personalDataExportSortedKeys($item))
        ->toBe([
            'created_at',
            'destination',
            'expires_at',
            'id',
            'short_url',
            'slug',
            'status',
            'updated_at',
        ]);

    expect($item['id'])->toBe($link->public_id)
        ->and($item['slug'])->toBe('export1')
        ->and($item['destination'])->toBe('https://example.com/export')
        ->and($item['short_url'])->toBe('https://iurl.fr/export1')
        ->and($item['status'])->toBe('active')
        ->and($item['expires_at'])->toBeNull();

    $keys = personalDataExportJsonKeys($body);

    foreach (['email_hash', 'password_hash', 'totp_secret', 'code_hash', 'used_at', 'country', 'ip', 'user_agent', 'referer'] as $forbidden) {
        expect($keys)->not->toContain($forbidden);
    }
});

it('exports the aggregated statistics of the workspace links', function () {
    $this->seed(LinkDomainSeeder::class);

    $workspace = Workspace::factory()->create(['name' => 'Stats workspace']);
    $user = User::factory()->create(['email' => 'stats-owner@example.com']);
    $workspace->members()->attach($user->id, ['role' => 'owner']);

    $domain = Domain::query()->where('hostname', 'iurl.fr')->firstOrFail();
    $link = Link::factory()->forDomain($domain)->create([
        'workspace_id' => $workspace->id,
        'slug' => 'exportstats1',
        'destination' => 'https://example.com/export-stats',
        'expires_at' => null,
    ]);

    $this->seedDailyStats($link->id, EXPORT_STATS_DAY, 5, 3);
    $this->seedWeeklyStats($link->id, 2026, 47, 4);
    $this->seedDeviceStats($link->id, EXPORT_STATS_DAY, 'mobile', 9);

    $otherWorkspace = Workspace::factory()->create(['name' => 'Other workspace']);
    $otherUser = User::factory()->create(['email' => 'other-stats-owner@example.com']);
    $otherWorkspace->members()->attach($otherUser->id, ['role' => 'owner']);

    $otherLink = Link::factory()->forDomain($domain)->create([
        'workspace_id' => $otherWorkspace->id,
        'slug' => 'exportstats2',
        'destination' => 'https://example.com/other-stats',
        'expires_at' => null,
    ]);

    $this->seedDailyStats($otherLink->id, EXPORT_STATS_DAY, 9, 9);

    Sanctum::actingAs($user, ['me:read']);

    $response = $this->getJson(PERSONAL_DATA_EXPORT_API.EXPORT_PATH);
    $response->assertOk();

    $body = $response->json();

    expect($body['statistics'])->toHaveCount(1);

    $statistics = $body['statistics'][0];

    expect(personalDataExportSortedKeys($statistics))
        ->toBe(['daily', 'devices', 'link_id', 'weekly']);

    expect($statistics['link_id'])->toBe($link->public_id);

    expect($statistics['daily'])->toHaveCount(1);
    expect(personalDataExportSortedKeys($statistics['daily'][0]))
        ->toBe(['clicks', 'date', 'uniques']);
    expect($statistics['daily'][0]['date'])->toBe(EXPORT_STATS_DAY)
        ->and($statistics['daily'][0]['clicks'])->toBe(5)
        ->and($statistics['daily'][0]['uniques'])->toBe(3);

    expect($statistics['weekly'])->toHaveCount(1);
    expect(personalDataExportSortedKeys($statistics['weekly'][0]))
        ->toBe(['iso_week', 'iso_year', 'uniques']);
    expect($statistics['weekly'][0]['iso_year'])->toBe(2026)
        ->and($statistics['weekly'][0]['iso_week'])->toBe(47)
        ->and($statistics['weekly'][0]['uniques'])->toBe(4);

    expect($statistics['devices'])->toHaveCount(1);
    expect(personalDataExportSortedKeys($statistics['devices'][0]))
        ->toBe(['clicks', 'device']);
    expect($statistics['devices'][0]['device'])->toBe('mobile')
        ->and($statistics['devices'][0]['clicks'])->toBe(9);

    $exportedLinks = array_column($body['statistics'], 'link_id');

    expect($exportedLinks)->toBe([$link->public_id]);

    $keys = personalDataExportJsonKeys($body);

    foreach (['country', 'ip', 'user_agent', 'referer'] as $forbidden) {
        expect($keys)->not->toContain($forbidden);
    }
});

it('drops the weekly buckets older than the retention window from the export', function () {
    $this->travelTo(CarbonImmutable::parse('2026-03-01 10:00:00', 'UTC'));
    config(['analytics.retention_days' => 30]);

    $this->seed(LinkDomainSeeder::class);

    $workspace = Workspace::factory()->create(['name' => 'Retained workspace']);
    $user = User::factory()->create(['email' => 'retained-owner@example.com']);
    $workspace->members()->attach($user->id, ['role' => 'owner']);

    $domain = Domain::query()->where('hostname', 'iurl.fr')->firstOrFail();
    $link = Link::factory()->forDomain($domain)->create([
        'workspace_id' => $workspace->id,
        'slug' => 'exportretained1',
        'destination' => 'https://example.com/export-retained',
        'expires_at' => null,
    ]);

    // The retained window opens on 2026-01-31, in ISO week 5: W4 is out.
    $this->seedWeeklyStats($link->id, 2026, 4, 7);
    $this->seedWeeklyStats($link->id, 2026, 5, 8);
    $this->seedWeeklyStats($link->id, 2026, 6, 9);
    $this->seedDailyStats($link->id, '2026-02-15', 4, 2);

    Sanctum::actingAs($user, ['me:read']);

    $response = $this->getJson(PERSONAL_DATA_EXPORT_API.EXPORT_PATH);
    $response->assertOk();

    $weekly = $response->json('statistics.0.weekly');

    $buckets = collect($weekly)
        ->map(fn (array $week): string => $week['iso_year'].'-W'.$week['iso_week'])
        ->sort()
        ->values()
        ->all();

    expect($buckets)->toBe(['2026-W5', '2026-W6']);
});
