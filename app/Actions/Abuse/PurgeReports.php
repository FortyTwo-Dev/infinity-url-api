<?php

declare(strict_types=1);

namespace App\Actions\Abuse;

use App\Models\Report;
use Carbon\CarbonImmutable;

/**
 * A report lives twelve months; a rejected one lives ninety days, long enough
 * to answer a contestation (docs/07 §9).
 */
final class PurgeReports
{
    /**
     * @return array{rejected: int, expired: int}
     */
    public function handle(?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now('UTC');

        $rejected = Report::query()
            ->where('state', Report::REJECTED)
            ->whereRaw('coalesce(resolved_at, created_at) < ?', [$now->subDays(90)->toDateTimeString()])
            ->delete();

        $expired = Report::query()
            ->where('created_at', '<', $now->subMonths(12))
            ->delete();

        return ['rejected' => $rejected, 'expired' => $expired];
    }
}
