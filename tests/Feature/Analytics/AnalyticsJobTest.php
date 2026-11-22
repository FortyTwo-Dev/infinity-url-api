<?php

use App\Enums\DeviceClass;
use App\Exceptions\AnalyticsJobMisconfiguredException;
use App\Services\Analytics\StatsStore;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Support\AnalyticsHelpers;

uses(AnalyticsHelpers::class);

const ANALYTICS_VISITOR_IP = '203.0.113.42';
const ANALYTICS_CLICK_TIME = '2026-11-21 12:00:00';
const ANALYTICS_CLICK_DAY = '2026-11-21';
const ANALYTICS_NEXT_DAY = '2026-11-22';
const ANALYTICS_NEXT_WEEK_DAY = '2026-11-23';

it('counts one click and its unique', function () {
    $this->travelTo(CarbonImmutable::parse(ANALYTICS_CLICK_TIME, 'UTC'));

    $link = $this->analyticsFixtureLink();

    $this->runClickJob($this->clickFor(ANALYTICS_VISITOR_IP, $link, ANALYTICS_CLICK_DAY));

    expect($this->dailyClicks($link->id, ANALYTICS_CLICK_DAY))->toBe(1);
    expect($this->dailyUniques($link->id, ANALYTICS_CLICK_DAY))->toBe(1);
    expect($this->weeklyUniques($link->id, 2026, 47))->toBe(1);
    expect($this->deviceClicks($link->id, ANALYTICS_CLICK_DAY, 'mobile'))->toBe(1);
});

it('counts a returning visitor twice but as a single unique', function () {
    $this->travelTo(CarbonImmutable::parse(ANALYTICS_CLICK_TIME, 'UTC'));

    $link = $this->analyticsFixtureLink();

    $this->runClickJob($this->clickFor(ANALYTICS_VISITOR_IP, $link, ANALYTICS_CLICK_DAY));
    $this->runClickJob($this->clickFor(ANALYTICS_VISITOR_IP, $link, ANALYTICS_CLICK_DAY));

    expect($this->dailyClicks($link->id, ANALYTICS_CLICK_DAY))->toBe(2);
    expect($this->dailyUniques($link->id, ANALYTICS_CLICK_DAY))->toBe(1);
    expect($this->weeklyUniques($link->id, 2026, 47))->toBe(1);
    expect($this->deviceClicks($link->id, ANALYTICS_CLICK_DAY, 'mobile'))->toBe(2);
});

it('scopes the dedup key to the link so two links do not correlate', function () {
    $this->travelTo(CarbonImmutable::parse(ANALYTICS_CLICK_TIME, 'UTC'));

    $first = $this->analyticsFixtureLink('jobcorr1');
    $second = $this->analyticsFixtureLink('jobcorr2');

    $this->runClickJob($this->clickFor(ANALYTICS_VISITOR_IP, $first, ANALYTICS_CLICK_DAY));
    $this->runClickJob($this->clickFor(ANALYTICS_VISITOR_IP, $second, ANALYTICS_CLICK_DAY));

    foreach ([$first, $second] as $link) {
        expect($this->dailyClicks($link->id, ANALYTICS_CLICK_DAY))->toBe(1);
        expect($this->dailyUniques($link->id, ANALYTICS_CLICK_DAY))->toBe(1);
        expect($this->weeklyUniques($link->id, 2026, 47))->toBe(1);
        expect($this->deviceClicks($link->id, ANALYTICS_CLICK_DAY, 'mobile'))->toBe(1);
    }
});

it('keeps a visitor unique across two days of the same ISO week', function () {
    $link = $this->analyticsFixtureLink();

    $this->travelTo(CarbonImmutable::parse(ANALYTICS_CLICK_TIME, 'UTC'));
    $this->runClickJob($this->clickFor(ANALYTICS_VISITOR_IP, $link, ANALYTICS_CLICK_DAY));

    $this->travelTo(CarbonImmutable::parse('2026-11-22 12:00:00', 'UTC'));
    $this->runClickJob($this->clickFor(ANALYTICS_VISITOR_IP, $link, ANALYTICS_NEXT_DAY));

    expect($this->dailyClicks($link->id, ANALYTICS_CLICK_DAY))->toBe(1);
    expect($this->dailyUniques($link->id, ANALYTICS_CLICK_DAY))->toBe(1);
    expect($this->dailyClicks($link->id, ANALYTICS_NEXT_DAY))->toBe(1);
    expect($this->dailyUniques($link->id, ANALYTICS_NEXT_DAY))->toBe(1);
    expect($this->weeklyUniques($link->id, 2026, 47))->toBe(1);
    expect($this->deviceClicks($link->id, ANALYTICS_CLICK_DAY, 'mobile'))->toBe(1);
    expect($this->deviceClicks($link->id, ANALYTICS_NEXT_DAY, 'mobile'))->toBe(1);
});

it('counts a visitor unique again in the next ISO week', function () {
    $link = $this->analyticsFixtureLink();

    $this->travelTo(CarbonImmutable::parse(ANALYTICS_CLICK_TIME, 'UTC'));
    $this->runClickJob($this->clickFor(ANALYTICS_VISITOR_IP, $link, ANALYTICS_CLICK_DAY));

    // The weekly dedup keys die at the Monday 00:00 UTC rotation, and Valkey's
    // clock is not the frozen one: model the boundary explicitly.
    Cache::flush();

    $this->travelTo(CarbonImmutable::parse('2026-11-23 12:00:00', 'UTC'));
    $this->runClickJob($this->clickFor(ANALYTICS_VISITOR_IP, $link, ANALYTICS_NEXT_WEEK_DAY));

    expect($this->dailyClicks($link->id, ANALYTICS_CLICK_DAY))->toBe(1);
    expect($this->dailyUniques($link->id, ANALYTICS_CLICK_DAY))->toBe(1);
    expect($this->dailyClicks($link->id, ANALYTICS_NEXT_WEEK_DAY))->toBe(1);
    expect($this->dailyUniques($link->id, ANALYTICS_NEXT_WEEK_DAY))->toBe(1);
    expect($this->weeklyUniques($link->id, 2026, 47))->toBe(1);
    expect($this->weeklyUniques($link->id, 2026, 48))->toBe(1);
    expect($this->deviceClicks($link->id, ANALYTICS_CLICK_DAY, 'mobile'))->toBe(1);
    expect($this->deviceClicks($link->id, ANALYTICS_NEXT_WEEK_DAY, 'mobile'))->toBe(1);
});

it('does not double the counters when the same job is retried', function () {
    $this->travelTo(CarbonImmutable::parse(ANALYTICS_CLICK_TIME, 'UTC'));

    $link = $this->analyticsFixtureLink();
    $job = $this->clickFor(ANALYTICS_VISITOR_IP, $link, ANALYTICS_CLICK_DAY);

    $this->runClickJob($job, 'retried-job-uuid');
    $this->runClickJob($job, 'retried-job-uuid');

    expect($this->dailyClicks($link->id, ANALYTICS_CLICK_DAY))->toBe(1);
    expect($this->dailyUniques($link->id, ANALYTICS_CLICK_DAY))->toBe(1);
    expect($this->weeklyUniques($link->id, 2026, 47))->toBe(1);
    expect($this->deviceClicks($link->id, ANALYTICS_CLICK_DAY, 'mobile'))->toBe(1);
});

it('never writes a country dimension', function () {
    $this->travelTo(CarbonImmutable::parse(ANALYTICS_CLICK_TIME, 'UTC'));

    $first = $this->analyticsFixtureLink('jobcountry1');
    $second = $this->analyticsFixtureLink('jobcountry2');

    $this->runClickJob($this->clickFor(ANALYTICS_VISITOR_IP, $first, ANALYTICS_CLICK_DAY));
    $this->runClickJob($this->clickFor(ANALYTICS_VISITOR_IP, $second, ANALYTICS_CLICK_DAY, DeviceClass::Desktop));

    expect(DB::table('link_stats_dim')->where('dimension', 'country')->count())->toBe(0);
});

it('counts the click but no unique when the salt is missing', function () {
    $this->travelTo(CarbonImmutable::parse(ANALYTICS_CLICK_TIME, 'UTC'));

    $link = $this->analyticsFixtureLink();

    $this->runClickJob($this->clickJob($link->id, ANALYTICS_CLICK_DAY, DeviceClass::Mobile, null, null));

    expect($this->dailyClicks($link->id, ANALYTICS_CLICK_DAY))->toBe(1);
    expect($this->dailyUniques($link->id, ANALYTICS_CLICK_DAY))->toBe(0);
    expect($this->weeklyUniques($link->id, 2026, 47))->toBe(0);
    expect($this->deviceClicks($link->id, ANALYTICS_CLICK_DAY, 'mobile'))->toBe(1);
});

it('refuses to run without a queue wrapper', function () {
    $this->travelTo(CarbonImmutable::parse(ANALYTICS_CLICK_TIME, 'UTC'));

    $link = $this->analyticsFixtureLink();
    $job = $this->clickFor(ANALYTICS_VISITOR_IP, $link, ANALYTICS_CLICK_DAY);

    expect(fn () => $job->handle(app(StatsStore::class)))
        ->toThrow(AnalyticsJobMisconfiguredException::class);
});

it('retries the job at most three times', function () {
    $link = $this->analyticsFixtureLink();

    $job = $this->clickFor(ANALYTICS_VISITOR_IP, $link, ANALYTICS_CLICK_DAY);

    expect($job->tries)->toBe(3);
});

it('deduplicates the daily unique inside the grace window', function () {
    config(['analytics.dedup_grace_minutes' => 60]);

    $link = $this->analyticsFixtureLink('grace1');

    // Same click date, same visitor, two jobs: the second one lands thirty
    // minutes after the day closed, inside the one-hour grace.
    $this->travelTo(CarbonImmutable::parse('2026-11-21 10:00:00', 'UTC'));
    $this->runClickJob($this->clickFor(ANALYTICS_VISITOR_IP, $link, ANALYTICS_CLICK_DAY));

    $this->travelTo(CarbonImmutable::parse('2026-11-22 00:30:00', 'UTC'));
    $this->runClickJob($this->clickFor(ANALYTICS_VISITOR_IP, $link, ANALYTICS_CLICK_DAY));

    expect($this->dailyClicks($link->id, ANALYTICS_CLICK_DAY))->toBe(2);
    expect($this->dailyUniques($link->id, ANALYTICS_CLICK_DAY))->toBe(1);
});

it('counts the click but no daily unique once the day is closed', function () {
    config(['analytics.dedup_grace_minutes' => 60]);

    $link = $this->analyticsFixtureLink('closedday1');

    // 2026-11-22 01:30 is past the one-hour grace of the day that closed at
    // midnight, while the ISO week (W47) is still open.
    $this->travelTo(CarbonImmutable::parse('2026-11-22 01:30:00', 'UTC'));
    $this->runClickJob($this->clickFor(ANALYTICS_VISITOR_IP, $link, ANALYTICS_CLICK_DAY));

    expect($this->dailyClicks($link->id, ANALYTICS_CLICK_DAY))->toBe(1);
    expect($this->dailyUniques($link->id, ANALYTICS_CLICK_DAY))->toBe(0);
    expect($this->weeklyUniques($link->id, 2026, 47))->toBe(1);
});

it('counts the click but no weekly unique once the week is closed', function () {
    config(['analytics.dedup_grace_minutes' => 60]);

    $link = $this->analyticsFixtureLink('closedweek1');

    // 2026-11-22 is the Sunday of W47, which closed on Monday 2026-11-23 at
    // 00:00 UTC; 01:30 is past the one-hour grace.
    $this->travelTo(CarbonImmutable::parse('2026-11-23 01:30:00', 'UTC'));
    $this->runClickJob($this->clickFor(ANALYTICS_VISITOR_IP, $link, ANALYTICS_NEXT_DAY));

    expect($this->dailyClicks($link->id, ANALYTICS_NEXT_DAY))->toBe(1);
    expect($this->dailyUniques($link->id, ANALYTICS_NEXT_DAY))->toBe(0);
    expect($this->weeklyUniques($link->id, 2026, 47))->toBe(0);
});
