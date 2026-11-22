<?php

declare(strict_types=1);

namespace App\Services\Analytics;

use App\Models\Workspace;
use App\Support\Analytics\RetentionWindow;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Read side of the aggregates. Counters are returned as integers, never as
 * strings, and uniques are only ever returned per bucket: they are not
 * additive and no method here sums them.
 */
final class StatsReader
{
    /**
     * @return list<array{date: string, clicks: int, uniques: int}>
     */
    public function daily(int $linkId, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $rows = DB::table('link_stats_daily')
            ->where('link_id', $linkId)
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->orderBy('date')
            ->get();

        return $this->shapeAll($rows, static fn (object $row): array => [
            'date' => (string) $row->date,
            'clicks' => (int) $row->clicks,
            'uniques' => (int) $row->uniques,
        ]);
    }

    /**
     * @param  list<array{iso_year: int, iso_week: int}>  $weeks
     * @return array<string, int>
     */
    public function weeklyUniques(int $linkId, array $weeks): array
    {
        if ($weeks === []) {
            return [];
        }

        $query = DB::table('link_stats_weekly')->where('link_id', $linkId);

        $query->where(function ($group) use ($weeks): void {
            foreach ($weeks as $week) {
                $group->orWhere(function ($pair) use ($week): void {
                    $pair->where('iso_year', $week['iso_year'])
                        ->where('iso_week', $week['iso_week']);
                });
            }
        });

        $uniques = [];

        foreach ($query->get() as $row) {
            $uniques[(int) $row->iso_year.'-'.(int) $row->iso_week] = (int) $row->uniques;
        }

        return $uniques;
    }

    /**
     * Clicks per device over the range. Additive, so summing them is sound.
     *
     * @return list<array{device: string, clicks: int}>
     */
    public function devices(int $linkId, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $rows = DB::table('link_stats_dim')
            ->where('link_id', $linkId)
            ->where('dimension', 'device')
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->select('value', DB::raw('sum(clicks) as clicks'))
            ->groupBy('value')
            ->orderByDesc('clicks')
            ->orderBy('value')
            ->get();

        return $this->shapeAll($rows, static fn (object $row): array => [
            'device' => (string) $row->value,
            'clicks' => (int) $row->clicks,
        ]);
    }

    /**
     * Every aggregate of a workspace, for the portability export.
     *
     * @return list<array{link_id: string, daily: list<array<string, mixed>>, weekly: list<array<string, mixed>>, devices: list<array<string, mixed>>}>
     */
    public function export(Workspace $workspace, CarbonImmutable $from): array
    {
        $links = $workspace->links()->orderBy('created_at')->get();

        if ($links->isEmpty()) {
            return [];
        }

        $ids = array_values(array_map(static fn (object $link): int => (int) $link->getKey(), $links->all()));
        $cutoff = RetentionWindow::weekCutoff($from);

        $daily = $this->groupByLink(
            DB::table('link_stats_daily')
                ->whereIn('link_id', $ids)
                ->where('date', '>=', $from->toDateString())
                ->orderBy('link_id')
                ->orderBy('date')
                ->get(),
            static fn (object $row): array => [
                'date' => (string) $row->date,
                'clicks' => (int) $row->clicks,
                'uniques' => (int) $row->uniques,
            ],
        );

        $weekly = $this->groupByLink(
            DB::table('link_stats_weekly')
                ->whereIn('link_id', $ids)
                ->where(function (Builder $query) use ($cutoff): void {
                    $query->where('iso_year', '>', $cutoff['iso_year'])
                        ->orWhere(function (Builder $inner) use ($cutoff): void {
                            $inner->where('iso_year', $cutoff['iso_year'])
                                ->where('iso_week', '>=', $cutoff['iso_week']);
                        });
                })
                ->orderBy('link_id')
                ->orderBy('iso_year')
                ->orderBy('iso_week')
                ->get(),
            static fn (object $row): array => [
                'iso_year' => (int) $row->iso_year,
                'iso_week' => (int) $row->iso_week,
                'uniques' => (int) $row->uniques,
            ],
        );

        $devices = $this->groupByLink(
            DB::table('link_stats_dim')
                ->whereIn('link_id', $ids)
                ->where('dimension', 'device')
                ->where('date', '>=', $from->toDateString())
                ->select('link_id', 'value', DB::raw('sum(clicks) as clicks'))
                ->groupBy('link_id', 'value')
                ->orderBy('link_id')
                ->orderBy('value')
                ->get(),
            static fn (object $row): array => [
                'device' => (string) $row->value,
                'clicks' => (int) $row->clicks,
            ],
        );

        $export = [];

        foreach ($links as $link) {
            $id = (int) $link->getKey();

            if (! isset($daily[$id]) && ! isset($weekly[$id]) && ! isset($devices[$id])) {
                continue;
            }

            $export[] = [
                'link_id' => (string) $link->public_id,
                'daily' => $daily[$id] ?? [],
                'weekly' => $weekly[$id] ?? [],
                'devices' => $devices[$id] ?? [],
            ];
        }

        return $export;
    }

    /**
     * @param  iterable<int, object>  $rows
     * @param  callable(object): array<string, mixed>  $shape
     * @return list<array<string, mixed>>
     */
    private function shapeAll(iterable $rows, callable $shape): array
    {
        $shaped = [];

        foreach ($rows as $row) {
            $shaped[] = $shape($row);
        }

        return $shaped;
    }

    /**
     * @param  iterable<int, object>  $rows
     * @param  callable(object): array<string, mixed>  $shape
     * @return array<int, list<array<string, mixed>>>
     */
    private function groupByLink(iterable $rows, callable $shape): array
    {
        $grouped = [];

        foreach ($rows as $row) {
            $grouped[(int) $row->link_id][] = $shape($row);
        }

        return $grouped;
    }
}
