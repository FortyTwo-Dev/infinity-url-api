<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\DeviceClass;
use App\Exceptions\AnalyticsJobMisconfiguredException;
use App\Services\Analytics\StatsStore;
use App\Support\Analytics\DedupWindow;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;

/**
 * One click, after the response.
 *
 * The payload carries no personal data: no IP, no user-agent, no slug, no
 * destination. A failed job can therefore be logged without leaking anything.
 */
final class RecordClick implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    /**
     * @var list<int>
     */
    public array $backoff = [10, 60, 300];

    public function __construct(
        public readonly int $linkId,
        public readonly string $date,
        public readonly DeviceClass $device,
        public readonly ?string $hashDaily,
        public readonly ?string $hashWeekly,
    ) {
        $this->onQueue((string) config('analytics.queue', 'analytics'));
    }

    public function handle(StatsStore $store): void
    {
        $uuid = $this->job?->uuid();

        if (! is_string($uuid) || $uuid === '') {
            throw new AnalyticsJobMisconfiguredException;
        }

        $ttl = now()->addHours((int) config('analytics.job_ttl_hours', 6));

        if (! Cache::add('job:'.$uuid, 1, $ttl)) {
            return;
        }

        $click = CarbonImmutable::parse($this->date, 'UTC');
        $now = CarbonImmutable::now('UTC');

        $uniqueDay = $this->claim(
            $this->hashDaily,
            'u:d:',
            DedupWindow::endOfDay($click),
            $now,
        );

        $uniqueWeek = $this->claim(
            $this->hashWeekly,
            'u:w:',
            DedupWindow::nextRotation($click),
            $now,
        );

        $store->record($this->linkId, $this->date, $this->device, $uniqueDay, $uniqueWeek);
    }

    /**
     * A bucket stays claimable for one hour after it closes, so the last click
     * of a day or of a week keeps deduplicating when its job runs late. Past
     * that grace the unique is abandoned rather than counted twice.
     */
    private function claim(
        ?string $hash,
        string $prefix,
        CarbonImmutable $closesAt,
        CarbonImmutable $now,
    ): bool {
        $expiresAt = $closesAt->addMinutes((int) config('analytics.dedup_grace_minutes', 60));

        if ($hash === null || ! $now->lessThan($expiresAt)) {
            return false;
        }

        return Cache::add($prefix.$hash, 1, $expiresAt);
    }
}
