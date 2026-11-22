<?php

declare(strict_types=1);

namespace App\Services\Abuse;

use App\Models\Report;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * One POST to the self-hosted ntfy topic. Best effort: a failed alert never
 * fails a report, and nothing about the reporter is sent — no address, no
 * email, no details, only the reference and the slug to triage.
 */
final class ReportNotifier
{
    public function received(Report $report): void
    {
        $url = config('abuse.ntfy_url');

        if (! is_string($url) || $url === '') {
            return;
        }

        try {
            Http::timeout(3)
                ->withHeaders(['Title' => 'New abuse report'])
                ->withBody(implode("\n", [
                    'Reference: '.$report->public_id,
                    'Link: '.$report->submitted_host.'/'.$report->submitted_slug,
                    'Category: '.$report->category,
                ]), 'text/plain')
                ->post($url);
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
