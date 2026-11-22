<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Enums\DeviceClass;
use App\Jobs\RecordClick;
use App\Models\Domain;
use App\Models\Link;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Analytics\AnalyticsSalt;
use App\Services\Analytics\DedupKey;
use App\Services\Analytics\StatsStore;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Seeding and inspection of the aggregates, plus a deterministic way to run
 * the analytics job without a worker.
 */
trait AnalyticsHelpers
{
    protected function analyticsFixtureLink(string $slug = 'job1'): Link
    {
        $domain = Domain::query()->where('hostname', 'iurl.fr')->first()
            ?? Domain::factory()->forHost('iurl.fr')->create();

        return Link::factory()->forDomain($domain)->create(['slug' => $slug]);
    }

    protected function analyticsLink(Workspace $workspace, string $slug, array $attributes = []): Link
    {
        return Link::factory()->forDomain($this->linkDomain())->create($attributes + [
            'workspace_id' => $workspace->id,
            'slug' => $slug,
        ]);
    }

    /**
     * One account with one link on iurl.fr, under the current salt.
     *
     * @return array{0: Workspace, 1: User, 2: Link}
     */
    protected function statsAccount(string $slug, ?string $email = null): array
    {
        [$workspace, $user] = $this->account($email);
        $link = $this->analyticsLink($workspace, $slug);

        return [$workspace, $user, $link];
    }

    /**
     * @param  array<string, mixed>  $value
     * @return array<int, string>
     */
    protected function sortedKeys(array $value): array
    {
        $keys = array_keys($value);
        sort($keys);

        return $keys;
    }

    protected function seedDailyStats(int $linkId, string $date, int $clicks, int $uniques): void
    {
        DB::table('link_stats_daily')->insert([
            'link_id' => $linkId,
            'date' => $date,
            'clicks' => $clicks,
            'uniques' => $uniques,
        ]);
    }

    protected function seedWeeklyStats(int $linkId, int $isoYear, int $isoWeek, int $uniques): void
    {
        DB::table('link_stats_weekly')->insert([
            'link_id' => $linkId,
            'iso_year' => $isoYear,
            'iso_week' => $isoWeek,
            'uniques' => $uniques,
        ]);
    }

    protected function seedDeviceStats(int $linkId, string $date, string $device, int $clicks): void
    {
        DB::table('link_stats_dim')->insert([
            'link_id' => $linkId,
            'date' => $date,
            'dimension' => 'device',
            'value' => $device,
            'clicks' => $clicks,
        ]);
    }

    /**
     * The reference fixture of the stats contract: two days, one week, two
     * devices.
     */
    protected function seedAnalyticsFixture(Link $link): void
    {
        $this->seedDailyStats($link->id, '2026-11-20', 5, 3);
        $this->seedDailyStats($link->id, '2026-11-21', 7, 5);
        $this->seedWeeklyStats($link->id, 2026, 47, 4);
        $this->seedDeviceStats($link->id, '2026-11-20', 'mobile', 9);
        $this->seedDeviceStats($link->id, '2026-11-21', 'desktop', 3);
    }

    protected function dailyClicks(int $linkId, string $date): int
    {
        return (int) (DB::table('link_stats_daily')
            ->where('link_id', $linkId)
            ->where('date', $date)
            ->value('clicks') ?? 0);
    }

    protected function dailyUniques(int $linkId, string $date): int
    {
        return (int) (DB::table('link_stats_daily')
            ->where('link_id', $linkId)
            ->where('date', $date)
            ->value('uniques') ?? 0);
    }

    protected function weeklyUniques(int $linkId, int $isoYear, int $isoWeek): int
    {
        return (int) (DB::table('link_stats_weekly')
            ->where('link_id', $linkId)
            ->where('iso_year', $isoYear)
            ->where('iso_week', $isoWeek)
            ->value('uniques') ?? 0);
    }

    protected function deviceClicks(int $linkId, string $date, string $device): int
    {
        return (int) (DB::table('link_stats_dim')
            ->where('link_id', $linkId)
            ->where('date', $date)
            ->where('dimension', 'device')
            ->where('value', $device)
            ->value('clicks') ?? 0);
    }

    protected function analyticsSalt(): string
    {
        $salt = app(AnalyticsSalt::class)->read();

        $this->assertIsString($salt);
        $this->assertNotSame('', $salt);

        return $salt;
    }

    /**
     * @return array{daily: string, weekly: string}
     */
    protected function dedupKeys(string $ip, int $linkId, string $date): array
    {
        $salt = $this->analyticsSalt();
        $key = new DedupKey;

        return [
            'daily' => $key->daily($ip, $linkId, $date, $salt),
            'weekly' => $key->weekly($ip, $linkId, $salt),
        ];
    }

    protected function clickJob(int $linkId, string $date, DeviceClass $device, ?string $hashDaily, ?string $hashWeekly): RecordClick
    {
        return new RecordClick($linkId, $date, $device, $hashDaily, $hashWeekly);
    }

    /**
     * A job for one click of the given visitor, with the hashes derived from
     * the current salt exactly as the hot path derives them.
     */
    protected function clickFor(string $ip, Link $link, string $date, DeviceClass $device = DeviceClass::Mobile): RecordClick
    {
        $keys = $this->dedupKeys($ip, $link->id, $date);

        return $this->clickJob($link->id, $date, $device, $keys['daily'], $keys['weekly']);
    }

    /**
     * Run the job inside a queue wrapper, as a worker would. The same uuid is
     * stable across retries, which is what the idempotence guard keys on.
     */
    protected function runClickJob(RecordClick $job, ?string $uuid = null): void
    {
        $job->setJob($this->syncQueueJob($uuid ?? (string) Str::ulid()));
        $job->handle(app(StatsStore::class));
    }

    protected function syncQueueJob(string $uuid): SyncJob
    {
        $payload = json_encode([
            'uuid' => $uuid,
            'displayName' => RecordClick::class,
            'job' => 'Illuminate\Queue\CallQueuedHandler@call',
            'maxTries' => 3,
            'data' => [],
        ], JSON_THROW_ON_ERROR);

        return new SyncJob(app(), $payload, 'sync', 'analytics');
    }

    protected function analyticsSaltPath(): string
    {
        $path = config('analytics.salt_path');

        $this->assertIsString($path);
        $this->assertNotSame('', $path);

        return $path;
    }

    protected function deleteSaltFile(): void
    {
        $path = $this->analyticsSaltPath();

        if (is_file($path)) {
            unlink($path);
        }

        clearstatcache();
    }

    /**
     * Read a CSV response whether it is streamed or buffered.
     */
    protected function csvBody(TestResponse $response): string
    {
        $base = $response->baseResponse;

        if ($base instanceof StreamedResponse) {
            return $response->streamedContent();
        }

        if ($base instanceof BinaryFileResponse) {
            return (string) file_get_contents($base->getFile()->getPathname());
        }

        return (string) $response->getContent();
    }

    /**
     * Every string key used anywhere in a decoded JSON body.
     *
     * @return array<int, string>
     */
    protected function jsonKeys(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $keys = [];

        foreach ($value as $key => $item) {
            if (is_string($key)) {
                $keys[] = $key;
            }

            $keys = [...$keys, ...$this->jsonKeys($item)];
        }

        return $keys;
    }
}
