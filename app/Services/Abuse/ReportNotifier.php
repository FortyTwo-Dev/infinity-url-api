<?php

declare(strict_types=1);

namespace App\Services\Abuse;

use App\Models\Report;

/**
 * One POST to the self-hosted ntfy topic. Best effort: a failed alert never
 * fails a report, and nothing about the reporter is sent — no address, no
 * email, no details, only the reference and the slug to triage.
 */
final class ReportNotifier
{
    public function __construct(private readonly Ntfy $ntfy) {}

    public function received(Report $report): void
    {
        $this->ntfy->send('New abuse report', implode("\n", [
            'Reference: '.$report->public_id,
            'Link: '.$report->submitted_host.'/'.$report->submitted_slug,
            'Category: '.$report->category,
        ]));
    }
}
