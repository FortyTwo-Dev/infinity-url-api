<?php

use Carbon\CarbonImmutable;
use Laravel\Sanctum\Sanctum;
use Tests\Support\AnalyticsHelpers;
use Tests\Support\Api;

uses(AnalyticsHelpers::class);

const CSV_FROZEN_AT = '2026-11-22 10:00:00';

/**
 * @return array<int, string>
 */
function analyticsCsvLines(string $body): array
{
    $lines = preg_split('/\r\n|\n/', trim($body)) ?: [];

    return array_values(array_filter($lines, static fn (string $line): bool => $line !== ''));
}

it('exports the daily series as csv', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-21 10:00:00', 'UTC'));

    [, $user, $link] = $this->statsAccount('csvday1');
    $this->seedAnalyticsFixture($link);
    Sanctum::actingAs($user, ['stats:read']);

    $query = ['from' => '2026-11-20', 'to' => '2026-11-21'];
    $series = $this->getJson(Api::linkStats($link->public_id, $query))->assertOk()->json('series');

    $response = $this->get(Api::linkStatsCsv($link->public_id, $query));
    $response->assertOk();

    expect((string) $response->headers->get('Content-Type'))->toStartWith('text/csv');
    expect((string) $response->headers->get('Content-Disposition'))
        ->toContain('attachment; filename="stats-'.$link->public_id.'.csv"');

    $body = $this->csvBody($response);
    $lines = analyticsCsvLines($body);

    expect($lines[0])->toBe('date,clicks,uniques');
    expect($lines)->toHaveCount(1 + count($series));

    foreach ($series as $index => $item) {
        expect($lines[$index + 1])->toBe($item['date'].','.$item['clicks'].','.$item['uniques']);
    }

    expect($body)->not->toContain('mobile');
    expect($body)->not->toContain('desktop');
});

it('exports the weekly series as csv', function () {
    $this->travelTo(CarbonImmutable::parse(CSV_FROZEN_AT, 'UTC'));

    [, $user, $link] = $this->statsAccount('csvweek1');
    $this->seedWeeklyStats($link->id, 2026, 45, 2);
    $this->seedWeeklyStats($link->id, 2026, 46, 3);
    Sanctum::actingAs($user, ['stats:read']);

    $query = [
        'from' => '2026-11-04',
        'to' => '2026-11-10',
        'interval' => 'week',
    ];
    $series = $this->getJson(Api::linkStats($link->public_id, $query))->assertOk()->json('series');

    $response = $this->get(Api::linkStatsCsv($link->public_id, $query));
    $response->assertOk();

    $lines = analyticsCsvLines($this->csvBody($response));

    expect($lines[0])->toBe('iso_year,iso_week,week_start,clicks,uniques');
    expect($lines)->toHaveCount(1 + count($series));

    foreach ($series as $index => $item) {
        expect($lines[$index + 1])->toBe(
            $item['iso_year'].','.$item['iso_week'].','.$item['week_start'].','.$item['clicks'].','.$item['uniques'],
        );
    }
});

it('requires the stats read scope on the csv export', function () {
    $this->travelTo(CarbonImmutable::parse(CSV_FROZEN_AT, 'UTC'));

    [, $user, $link] = $this->statsAccount('csvscope1');
    Sanctum::actingAs($user, ['links:read']);

    $this->assertProblem(
        $this->get(Api::linkStatsCsv($link->public_id)),
        403,
        'scope_missing',
    );
});

it('answers not found for another workspace link on the csv export', function () {
    $this->travelTo(CarbonImmutable::parse(CSV_FROZEN_AT, 'UTC'));

    [, $user] = $this->statsAccount('csvown1');
    [, , $otherLink] = $this->statsAccount('csvother1', 'other-csv@example.com');
    Sanctum::actingAs($user, ['stats:read']);

    $this->assertProblem(
        $this->get(Api::linkStatsCsv($otherLink->public_id)),
        404,
        'link_not_found',
    );
});
