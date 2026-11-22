<?php

declare(strict_types=1);

namespace App\Actions\Abuse;

use App\Models\Report;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

final class ResolveReport
{
    public function handle(Report $report, string $state, ?string $reason): void
    {
        if (! in_array($state, [Report::ACTIONED, Report::REJECTED], true)) {
            throw new InvalidArgumentException('Unknown report outcome: '.$state);
        }

        $report->state = $state;
        $report->resolution = $reason;
        $report->resolved_at = CarbonImmutable::now('UTC');
        $report->save();
    }
}
