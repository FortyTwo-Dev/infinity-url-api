<?php

declare(strict_types=1);

namespace App\Actions\Analytics;

use App\Models\Link;
use App\Services\Analytics\StatsReader;
use App\Support\Analytics\StatsRange;
use Carbon\CarbonImmutable;

/**
 * The statistics payload of one link.
 *
 * `series` holds one bucket per day or per ISO week; `uniques` stays inside a
 * bucket and is never summed across buckets. `summary` only totals clicks,
 * which are additive.
 */
final class LinkStats
{
    public function __construct(private readonly StatsReader $reader) {}

    /**
     * @return array{link_id: string, interval: string, from: string, to: string, retention_days: int, clamped: bool, summary: array{clicks: int}, series: list<array<string, mixed>>, devices: list<array{device: string, clicks: int}>}
     */
    public function handle(Link $link, StatsRange $range): array
    {
        $linkId = (int) $link->getKey();
        $daily = $range->empty ? [] : $this->reader->daily($linkId, $range->from, $range->to);

        return [
            'link_id' => (string) $link->public_id,
            'interval' => $range->interval,
            'from' => $range->from->toDateString(),
            'to' => $range->to->toDateString(),
            'retention_days' => $range->retentionDays,
            'clamped' => $range->clamped,
            'summary' => ['clicks' => $this->totalClicks($daily)],
            'series' => $range->interval === 'week'
                ? $this->weeklySeries($linkId, $daily)
                : $daily,
            'devices' => $range->empty ? [] : $this->reader->devices($linkId, $range->from, $range->to),
        ];
    }

    /**
     * @param  list<array{date: string, clicks: int, uniques: int}>  $daily
     * @return list<array{iso_year: int, iso_week: int, week_start: string, clicks: int, uniques: int}>
     */
    private function weeklySeries(int $linkId, array $daily): array
    {
        $index = [];
        $series = [];
        $weeks = [];

        foreach ($daily as $row) {
            $date = CarbonImmutable::parse($row['date'], 'UTC');
            $key = $date->isoWeekYear().'-'.$date->isoWeek();

            if (! isset($index[$key])) {
                $index[$key] = count($series);
                $series[] = [
                    'iso_year' => (int) $date->isoWeekYear(),
                    'iso_week' => (int) $date->isoWeek(),
                    'week_start' => $date->startOfWeek(CarbonImmutable::MONDAY)->toDateString(),
                    'clicks' => 0,
                    'uniques' => 0,
                ];
                $weeks[] = [
                    'iso_year' => (int) $date->isoWeekYear(),
                    'iso_week' => (int) $date->isoWeek(),
                ];
            }

            $position = $index[$key];
            $bucket = $series[$position];
            $bucket['clicks'] += $row['clicks'];
            $series[$position] = $bucket;
        }

        $uniques = $this->reader->weeklyUniques($linkId, $weeks);

        foreach ($series as $position => $bucket) {
            $bucket['uniques'] = $uniques[$bucket['iso_year'].'-'.$bucket['iso_week']] ?? 0;
            $series[$position] = $bucket;
        }

        return $series;
    }

    /**
     * @param  list<array{date: string, clicks: int, uniques: int}>  $daily
     */
    private function totalClicks(array $daily): int
    {
        $clicks = 0;

        foreach ($daily as $row) {
            $clicks += $row['clicks'];
        }

        return $clicks;
    }
}
