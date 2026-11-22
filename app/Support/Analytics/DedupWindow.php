<?php

declare(strict_types=1);

namespace App\Support\Analytics;

use Carbon\CarbonImmutable;

/**
 * The two dedup windows, derived from the click instant.
 *
 * The salt rotates every Monday at 00:00 UTC; a weekly fingerprint computed
 * after the rotation can never match one computed before it.
 */
final class DedupWindow
{
    public static function endOfDay(CarbonImmutable $at): CarbonImmutable
    {
        return $at->utc()->endOfDay();
    }

    public static function nextRotation(CarbonImmutable $at): CarbonImmutable
    {
        $monday = $at->utc()->startOfWeek(CarbonImmutable::MONDAY);

        return $monday->isAfter($at->utc()) ? $monday : $monday->addWeek();
    }
}
