<?php

declare(strict_types=1);

namespace App\Actions\Analytics;

use App\Support\Analytics\RetentionWindow;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Daily purge of the aggregates. Rows are kept for exactly `retention_days`
 * calendar days including today; a weekly bucket is only removed once its whole
 * ISO week is older than the window.
 */
final class PurgeStats
{
    public function handle(): int
    {
        $days = (int) config('analytics.retention_days', 30);
        $before = RetentionWindow::purgeBefore(CarbonImmutable::now('UTC'), $days);
        $cutoff = RetentionWindow::weekCutoff($before);

        return DB::transaction(function () use ($before, $cutoff): int {
            $date = $before->toDateString();

            $daily = DB::table('link_stats_daily')->where('date', '<', $date)->delete();
            $dimensions = DB::table('link_stats_dim')->where('date', '<', $date)->delete();
            $weekly = $this->purgeWeekly($cutoff);

            return $daily + $dimensions + $weekly;
        });
    }

    /**
     * @param  array{iso_year: int, iso_week: int}  $cutoff
     */
    private function purgeWeekly(array $cutoff): int
    {
        return DB::table('link_stats_weekly')
            ->where('iso_year', '<', $cutoff['iso_year'])
            ->orWhere(function (Builder $query) use ($cutoff): void {
                $query->where('iso_year', $cutoff['iso_year'])
                    ->where('iso_week', '<', $cutoff['iso_week']);
            })
            ->delete();
    }
}
