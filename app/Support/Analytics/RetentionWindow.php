<?php

declare(strict_types=1);

namespace App\Support\Analytics;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Aggregate retention, in calendar days including today.
 *
 * `purgeBefore(2026-03-01, 30)` is 2026-01-31: thirty days ending today, never
 * a subtraction of "30 days" that would keep thirty-one.
 */
final class RetentionWindow
{
    public static function purgeBefore(CarbonImmutable $today, int $days): CarbonImmutable
    {
        if ($days < 1) {
            throw new InvalidArgumentException('Retention must be at least one day.');
        }

        return $today->utc()->startOfDay()->subDays($days - 1);
    }

    /**
     * @return array{iso_year: int, iso_week: int}
     */
    public static function weekCutoff(CarbonImmutable $purgeBefore): array
    {
        return [
            'iso_year' => (int) $purgeBefore->isoWeekYear(),
            'iso_week' => (int) $purgeBefore->isoWeek(),
        ];
    }
}
