<?php

declare(strict_types=1);

namespace App\Services\Analytics;

use App\Jobs\RecordClick;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Clicks waiting to be pushed once the response has been sent.
 *
 * The terminating callback is registered once, at boot, and drains this buffer:
 * registering a callback per click would accumulate callbacks on a long-lived
 * application instance and replay previous clicks on the next termination.
 */
final class PendingClicks
{
    /**
     * @var list<RecordClick>
     */
    private array $jobs = [];

    public function push(RecordClick $job): void
    {
        $this->jobs[] = $job;
    }

    public function flush(): void
    {
        $jobs = $this->jobs;
        $this->jobs = [];

        foreach ($jobs as $job) {
            $this->dispatch($job);
        }
    }

    private function dispatch(RecordClick $job): void
    {
        try {
            dispatch($job);
        } catch (Throwable $exception) {
            Log::critical('analytics enqueue failed', [
                'link_id' => $job->linkId,
                'date' => $job->date,
            ]);

            report($exception);
        }
    }
}
