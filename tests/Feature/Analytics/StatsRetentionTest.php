<?php

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Support\AnalyticsHelpers;
use Tests\Support\Api;

uses(AnalyticsHelpers::class);

it('purges the aggregates older than the retention window', function () {
    $this->travelTo(CarbonImmutable::parse('2026-03-01 10:00:00', 'UTC'));
    config(['analytics.retention_days' => 30]);

    $link = $this->analyticsFixtureLink('retention1');

    $this->seedDailyStats($link->id, '2026-01-30', 3, 1);
    $this->seedDailyStats($link->id, '2026-01-31', 4, 2);
    $this->seedDeviceStats($link->id, '2026-01-30', 'mobile', 3);
    $this->seedDeviceStats($link->id, '2026-01-31', 'mobile', 4);
    $this->seedWeeklyStats($link->id, 2026, 4, 7);
    $this->seedWeeklyStats($link->id, 2026, 5, 8);

    $this->artisan('analytics:purge-stats')->assertSuccessful();

    expect(DB::table('link_stats_daily')
        ->where('link_id', $link->id)
        ->orderBy('date')
        ->pluck('date')
        ->all())->toBe(['2026-01-31']);

    expect(DB::table('link_stats_dim')
        ->where('link_id', $link->id)
        ->orderBy('date')
        ->pluck('date')
        ->all())->toBe(['2026-01-31']);

    expect(DB::table('link_stats_weekly')
        ->where('link_id', $link->id)
        ->orderBy('iso_week')
        ->pluck('iso_week')
        ->all())->toBe([5]);
});

it('never serves a row older than the retention window', function () {
    $this->travelTo(CarbonImmutable::parse('2026-03-01 10:00:00', 'UTC'));
    config(['analytics.retention_days' => 30]);

    [, $user, $link] = $this->statsAccount('retained1');
    $this->seedDailyStats($link->id, '2026-01-30', 9, 9);
    $this->seedDailyStats($link->id, '2026-02-15', 4, 2);
    Sanctum::actingAs($user, ['stats:read']);

    $response = $this->getJson(Api::linkStats($link->public_id, [
        'from' => '2026-01-01',
        'to' => '2026-03-01',
    ]));

    $response->assertOk();

    expect($response->json('from'))->toBe('2026-01-31');
    expect(array_column($response->json('series'), 'date'))->toBe(['2026-02-15']);
    expect($response->json('summary.clicks'))->toBe(4);
});

it('serves an empty report for a window that holds no whole retained week', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-22 10:00:00', 'UTC'));
    config(['analytics.retention_days' => 30]);

    [, $user, $link] = $this->statsAccount('partialweek1');

    // 2026-10-24 is the Saturday that opens the retained window, and
    // 2026-10-25 is the Sunday of the same ISO week (W43).
    $this->seedDailyStats($link->id, '2026-10-24', 2, 1);
    $this->seedDailyStats($link->id, '2026-10-25', 3, 2);
    $this->seedWeeklyStats($link->id, 2026, 43, 3);

    Sanctum::actingAs($user, ['stats:read']);

    $response = $this->getJson(Api::linkStats($link->public_id, [
        'from' => '2026-10-24',
        'to' => '2026-10-25',
        'interval' => 'week',
    ]));

    $response->assertOk();

    expect($response->json('from'))->toBe('2026-10-24');
    expect($response->json('to'))->toBe('2026-10-25');
    expect($response->json('clamped'))->toBeTrue();
    expect($response->json('series'))->toBe([]);
    expect($response->json('devices'))->toBe([]);
    expect($response->json('summary'))->toBe(['clicks' => 0]);
});
