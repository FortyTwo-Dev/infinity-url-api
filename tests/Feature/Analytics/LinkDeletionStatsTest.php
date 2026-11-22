<?php

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Support\AnalyticsHelpers;
use Tests\Support\Api;

uses(AnalyticsHelpers::class);

it('erases the counters when a link is deleted through the api', function () {
    [, $user, $link] = $this->statsAccount('deletestats1');
    [, , $otherLink] = $this->statsAccount('deletestats2', 'other-delete@example.com');

    $this->seedAnalyticsFixture($link);
    $this->seedAnalyticsFixture($otherLink);

    Sanctum::actingAs($user, ['links:write']);

    $this->deleteJson(Api::LINKS.'/'.$link->public_id)->assertStatus(204);

    foreach (['link_stats_daily', 'link_stats_weekly', 'link_stats_dim'] as $table) {
        expect(DB::table($table)->where('link_id', $link->id)->count())->toBe(0);
        expect(DB::table($table)->where('link_id', $otherLink->id)->count())->toBeGreaterThan(0);
    }
});

it('erases the counters when a link is force deleted', function () {
    $link = $this->analyticsFixtureLink('forcedelete1');
    $this->seedAnalyticsFixture($link);

    $link->forceDelete();

    foreach (['link_stats_daily', 'link_stats_weekly', 'link_stats_dim'] as $table) {
        expect(DB::table($table)->where('link_id', $link->id)->count())->toBe(0);
    }
});

it('never recreates the counters of a link deleted through the api', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-21 10:00:00', 'UTC'));

    [, $user, $link] = $this->statsAccount('deletedjob1');
    $job = $this->clickFor('203.0.113.42', $link, '2026-11-21');

    Sanctum::actingAs($user, ['links:write']);

    $this->deleteJson(Api::LINKS.'/'.$link->public_id)->assertStatus(204);

    $this->runClickJob($job);

    foreach (['link_stats_daily', 'link_stats_weekly', 'link_stats_dim'] as $table) {
        expect(DB::table($table)->where('link_id', $link->id)->count())->toBe(0);
    }
});
