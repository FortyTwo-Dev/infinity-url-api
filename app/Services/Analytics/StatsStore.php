<?php

declare(strict_types=1);

namespace App\Services\Analytics;

use App\Enums\DeviceClass;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Atomic counter writes.
 *
 * Every statement is an insert-on-conflict that increments in place: two
 * concurrent jobs on the same link must never lose an increment, so there is
 * never a select followed by an update.
 */
final class StatsStore
{
    public function record(
        int $linkId,
        string $date,
        DeviceClass $device,
        bool $uniqueDay,
        bool $uniqueWeek,
    ): void {
        $day = CarbonImmutable::parse($date, 'UTC');

        DB::transaction(function () use ($linkId, $date, $device, $uniqueDay, $uniqueWeek, $day): void {
            // Serialise with DeleteLink: a late job must never recreate the
            // counters of a link that was deleted meanwhile.
            $link = DB::table('links')->where('id', $linkId)->lockForUpdate()->first(['deleted_at']);

            if ($link === null || $link->deleted_at !== null) {
                return;
            }

            $this->incrementDaily($linkId, $date, $uniqueDay);
            $this->incrementWeekly($linkId, (int) $day->isoWeekYear(), (int) $day->isoWeek(), $uniqueWeek);
            $this->incrementDevice($linkId, $date, $device);
        });
    }

    public function forgetLink(int $linkId): void
    {
        DB::table('link_stats_daily')->where('link_id', $linkId)->delete();
        DB::table('link_stats_weekly')->where('link_id', $linkId)->delete();
        DB::table('link_stats_dim')->where('link_id', $linkId)->delete();
    }

    private function incrementDaily(int $linkId, string $date, bool $unique): void
    {
        DB::statement(
            <<<'SQL'
                insert into link_stats_daily (link_id, date, clicks, uniques)
                values (?, ?::date, 1, ?)
                on conflict (link_id, date) do update
                set clicks  = link_stats_daily.clicks + 1,
                    uniques = link_stats_daily.uniques + excluded.uniques
                SQL,
            [$linkId, $date, $unique ? 1 : 0],
        );
    }

    private function incrementWeekly(int $linkId, int $isoYear, int $isoWeek, bool $unique): void
    {
        DB::statement(
            <<<'SQL'
                insert into link_stats_weekly (link_id, iso_year, iso_week, uniques)
                values (?, ?, ?, ?)
                on conflict (link_id, iso_year, iso_week) do update
                set uniques = link_stats_weekly.uniques + excluded.uniques
                SQL,
            [$linkId, $isoYear, $isoWeek, $unique ? 1 : 0],
        );
    }

    private function incrementDevice(int $linkId, string $date, DeviceClass $device): void
    {
        DB::statement(
            <<<'SQL'
                insert into link_stats_dim (link_id, date, dimension, value, clicks)
                values (?, ?::date, 'device', ?, 1)
                on conflict (link_id, date, dimension, value) do update
                set clicks = link_stats_dim.clicks + 1
                SQL,
            [$linkId, $date, $device->value],
        );
    }
}
