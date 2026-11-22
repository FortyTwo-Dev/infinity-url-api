<?php

declare(strict_types=1);

namespace App\Actions\Abuse;

use App\Enums\LinkStatus;
use App\Mail\LinkBlocked;
use App\Models\Link;
use App\Models\Report;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;

/**
 * Operator action, never automatic (docs/04 §4.2). The link becomes `blocked`,
 * the cache is invalidated by the observer, the statistics are kept, and the
 * owner receives the motive: the DSA requires a motivated restriction.
 */
final class BlockLink
{
    public function handle(Link $link, string $reason, ?Report $report = null): void
    {
        $link->status = LinkStatus::Blocked;
        $link->save();

        $owner = $this->owner($link);

        if ($owner !== null) {
            Mail::to($owner->email)->send(new LinkBlocked($reason, $this->dashboardUrl($link)));
        }

        if ($report !== null) {
            $report->state = Report::ACTIONED;
            $report->resolution = 'blocked: '.$reason;
            $report->resolved_at = CarbonImmutable::now('UTC');
            $report->save();
        }
    }

    private function owner(Link $link): ?User
    {
        $creator = $link->created_by === null ? null : User::query()->find($link->created_by);

        if ($creator !== null) {
            return $creator;
        }

        return $link->workspace?->members()->wherePivot('role', 'owner')->first();
    }

    private function dashboardUrl(Link $link): string
    {
        return rtrim((string) config('identity.dashboard_url'), '/').'/links/'.$link->public_id;
    }
}
