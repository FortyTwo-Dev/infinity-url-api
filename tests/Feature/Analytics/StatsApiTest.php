<?php

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Support\AnalyticsHelpers;
use Tests\Support\Api;

uses(AnalyticsHelpers::class);

const STATS_FROZEN_AT = '2026-11-22 10:00:00';
const STATS_FIRST_DAY = '2026-11-20';
const STATS_SECOND_DAY = '2026-11-21';
const STATS_LAST_DAY = '2026-11-22';

it('serves a daily series, devices and a click summary', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-21 10:00:00', 'UTC'));

    [, $user, $link] = $this->statsAccount('statsday1');
    $this->seedAnalyticsFixture($link);
    Sanctum::actingAs($user, ['stats:read']);

    $response = $this->getJson(Api::linkStats($link->public_id, [
        'from' => STATS_FIRST_DAY,
        'to' => STATS_SECOND_DAY,
    ]));

    $response->assertOk();
    $body = $response->json();

    expect($this->sortedKeys($body))->toBe([
        'clamped',
        'devices',
        'from',
        'interval',
        'link_id',
        'retention_days',
        'series',
        'summary',
        'to',
    ]);

    expect($body['link_id'])->toBe($link->public_id)
        ->and($body['from'])->toBe(STATS_FIRST_DAY)
        ->and($body['to'])->toBe(STATS_SECOND_DAY)
        ->and($body['clamped'])->toBeFalse()
        ->and($body['interval'])->toBe('day')
        ->and($body['retention_days'])->toBe(30);

    expect($body['series'])->toHaveCount(2);

    foreach ($body['series'] as $item) {
        expect($this->sortedKeys($item))->toBe(['clicks', 'date', 'uniques']);
    }

    expect($body['series'][0]['date'])->toBe(STATS_FIRST_DAY)
        ->and($body['series'][0]['clicks'])->toBe(5)
        ->and($body['series'][0]['uniques'])->toBe(3)
        ->and($body['series'][1]['date'])->toBe(STATS_SECOND_DAY)
        ->and($body['series'][1]['clicks'])->toBe(7)
        ->and($body['series'][1]['uniques'])->toBe(5);

    expect($body['devices'])->toBe([
        ['device' => 'mobile', 'clicks' => 9],
        ['device' => 'desktop', 'clicks' => 3],
    ]);

    foreach ($body['devices'] as $device) {
        expect($this->sortedKeys($device))->toBe(['clicks', 'device']);
        expect($device)->not->toHaveKey('uniques');
    }

    expect($body['summary'])->toBe(['clicks' => 12]);
    expect($body)->not->toHaveKey('uniques');
    expect($this->jsonKeys($body))->not->toContain('country');
});

it('defaults to the last thirty days', function () {
    $this->travelTo(CarbonImmutable::parse(STATS_FROZEN_AT, 'UTC'));

    [, $user, $link] = $this->statsAccount('statsdefault1');
    $this->seedAnalyticsFixture($link);
    Sanctum::actingAs($user, ['stats:read']);

    $response = $this->getJson(Api::linkStats($link->public_id));

    $response->assertOk();
    $body = $response->json();

    expect($body['from'])->toBe('2026-10-24')
        ->and($body['to'])->toBe(STATS_LAST_DAY)
        ->and($body['clamped'])->toBeFalse()
        ->and($body['retention_days'])->toBe(30);
});

it('clamps a range wider than the retention window', function () {
    $this->travelTo(CarbonImmutable::parse(STATS_FROZEN_AT, 'UTC'));

    [, $user, $link] = $this->statsAccount('statsclamp1');
    Sanctum::actingAs($user, ['stats:read']);

    $response = $this->getJson(Api::linkStats($link->public_id, [
        'from' => '2026-01-01',
        'to' => STATS_LAST_DAY,
    ]));

    $response->assertOk();

    expect($response->json('from'))->toBe('2026-10-24');
    expect($response->json('to'))->toBe(STATS_LAST_DAY);
    expect($response->json('clamped'))->toBeTrue();
});

it('serves an empty series for a link without counters', function () {
    $this->travelTo(CarbonImmutable::parse(STATS_FROZEN_AT, 'UTC'));

    [, $user, $link] = $this->statsAccount('statsempty1');
    Sanctum::actingAs($user, ['stats:read']);

    $response = $this->getJson(Api::linkStats($link->public_id));

    $response->assertOk();

    expect($response->json('series'))->toBe([]);
    expect($response->json('devices'))->toBe([]);
    expect($response->json('summary'))->toBe(['clicks' => 0]);
});

it('serves whole ISO weeks when the interval is week', function () {
    $this->travelTo(CarbonImmutable::parse(STATS_FROZEN_AT, 'UTC'));

    [, $user, $link] = $this->statsAccount('statsweek1');
    $this->seedDailyStats($link->id, '2026-11-03', 4, 2);
    $this->seedDailyStats($link->id, '2026-11-10', 6, 3);
    $this->seedWeeklyStats($link->id, 2026, 45, 2);
    $this->seedWeeklyStats($link->id, 2026, 46, 3);
    Sanctum::actingAs($user, ['stats:read']);

    $response = $this->getJson(Api::linkStats($link->public_id, [
        'from' => '2026-11-04',
        'to' => '2026-11-10',
        'interval' => 'week',
    ]));

    $response->assertOk();
    $body = $response->json();

    expect($body['from'])->toBe('2026-11-02')
        ->and($body['to'])->toBe('2026-11-15')
        ->and($body['clamped'])->toBeTrue()
        ->and($body['interval'])->toBe('week');

    expect($body['series'])->toHaveCount(2);

    foreach ($body['series'] as $item) {
        expect($this->sortedKeys($item))->toBe([
            'clicks',
            'iso_week',
            'iso_year',
            'uniques',
            'week_start',
        ]);
        expect(CarbonImmutable::parse((string) $item['week_start'], 'UTC')->isMonday())->toBeTrue();
    }

    expect($body['series'][0]['iso_year'])->toBe(2026)
        ->and($body['series'][0]['iso_week'])->toBe(45)
        ->and($body['series'][0]['week_start'])->toBe('2026-11-02')
        ->and($body['series'][0]['uniques'])->toBe(2)
        ->and($body['series'][1]['iso_year'])->toBe(2026)
        ->and($body['series'][1]['iso_week'])->toBe(46)
        ->and($body['series'][1]['week_start'])->toBe('2026-11-09')
        ->and($body['series'][1]['uniques'])->toBe(3);
});

dataset('invalid stats queries', [
    'unsupported interval' => [['interval' => 'month']],
    'from after to' => [['from' => STATS_FIRST_DAY, 'to' => '2026-11-19']],
    'impossible date' => [['from' => '2026-13-01']],
]);

it('rejects an invalid stats query', function (array $query) {
    $this->travelTo(CarbonImmutable::parse(STATS_FROZEN_AT, 'UTC'));

    [, $user, $link] = $this->statsAccount('statsinvalid1');
    Sanctum::actingAs($user, ['stats:read']);

    $this->assertProblem(
        $this->getJson(Api::linkStats($link->public_id, $query)),
        422,
        'validation_failed',
    );
})->with('invalid stats queries');

it('requires the stats read scope', function () {
    $this->travelTo(CarbonImmutable::parse(STATS_FROZEN_AT, 'UTC'));

    [, $user, $link] = $this->statsAccount('statsscope1');
    Sanctum::actingAs($user, ['links:read']);

    $this->assertProblem(
        $this->getJson(Api::linkStats($link->public_id)),
        403,
        'scope_missing',
    );
});

it('answers not found for another workspace link', function () {
    $this->travelTo(CarbonImmutable::parse(STATS_FROZEN_AT, 'UTC'));

    [, $user] = $this->statsAccount('statsown1');
    [, , $otherLink] = $this->statsAccount('statsother1', 'other-stats@example.com');
    $this->seedDailyStats($otherLink->id, STATS_SECOND_DAY, 4, 2);
    Sanctum::actingAs($user, ['stats:read']);

    $this->assertProblem(
        $this->getJson(Api::linkStats($otherLink->public_id)),
        404,
        'link_not_found',
    );
});

it('answers not found for an unknown link id', function () {
    $this->travelTo(CarbonImmutable::parse(STATS_FROZEN_AT, 'UTC'));

    [, $user] = $this->statsAccount('statsunknown1');
    Sanctum::actingAs($user, ['stats:read']);

    $this->assertProblem(
        $this->getJson(Api::linkStats((string) Str::ulid())),
        404,
        'link_not_found',
    );
});

it('leaves the link resource free of the seven day click counter', function () {
    $this->travelTo(CarbonImmutable::parse(STATS_FROZEN_AT, 'UTC'));

    [, $user, $link] = $this->statsAccount('statsresource1');
    $this->seedAnalyticsFixture($link);
    Sanctum::actingAs($user, ['links:read']);

    $response = $this->getJson(Api::LINKS.'/'.$link->public_id);

    $response->assertOk();
    expect($this->jsonKeys($response->json()))->not->toContain('clicks_7d');
});
