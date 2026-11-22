<?php

declare(strict_types=1);

namespace App\Actions\Analytics;

use App\Jobs\RecordClick;
use App\Services\Analytics\AnalyticsSalt;
use App\Services\Analytics\DedupKey;
use App\Services\Analytics\DeviceClassifier;
use App\Services\Analytics\PendingClicks;
use App\Support\RedirectResult;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Everything that needs the IP happens here, inside the request: the two
 * fingerprints are computed, the device is classified, then the raw IP and the
 * user-agent are abandoned. The job is pushed after the response, so a slow or
 * broken queue can never delay a visitor.
 */
final class CaptureClick
{
    public function __construct(
        private readonly AnalyticsSalt $salt,
        private readonly DedupKey $dedup,
        private readonly DeviceClassifier $classifier,
        private readonly PendingClicks $clicks,
    ) {}

    public function handle(Request $request, RedirectResult $result, CarbonImmutable $now): void
    {
        try {
            $this->capture($request, $result, $now);
        } catch (Throwable $exception) {
            // A parsing exception from device-detector can carry the raw
            // user-agent in its message: log the class only, never the trace.
            Log::error('analytics capture failed', ['error' => $exception::class]);
        }
    }

    private function capture(Request $request, RedirectResult $result, CarbonImmutable $now): void
    {
        if ($result->status !== 302 || $result->linkId === null) {
            return;
        }

        if ($request->isMethod('HEAD') || $this->isPrefetch($request)) {
            return;
        }

        $observation = $this->classifier->classify($request->userAgent());

        if ($observation->isBot) {
            return;
        }

        $date = $now->utc()->toDateString();
        $salt = $this->salt->current();

        if ($salt === null) {
            Log::critical('analytics salt unavailable', [
                'link_id' => $result->linkId,
                'date' => $date,
            ]);
        }

        $job = new RecordClick(
            $result->linkId,
            $date,
            $observation->device,
            $salt === null ? null : $this->dedup->daily((string) $request->ip(), $result->linkId, $date, $salt),
            $salt === null ? null : $this->dedup->weekly((string) $request->ip(), $result->linkId, $salt),
        );

        $this->clicks->push($job);
    }

    private function isPrefetch(Request $request): bool
    {
        $secPurpose = strtolower((string) $request->header('Sec-Purpose', ''));

        if (str_contains($secPurpose, 'prefetch')) {
            return true;
        }

        if (strtolower((string) $request->header('Purpose', '')) === 'prefetch') {
            return true;
        }

        return strtolower((string) $request->header('X-Purpose', '')) === 'preview';
    }
}
