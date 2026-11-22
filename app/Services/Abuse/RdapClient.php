<?php

declare(strict_types=1);

namespace App\Services\Abuse;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Domain age, the signal that generalises: a young domain serving a login form
 * is suspicious whatever brand it imitates (D-133).
 *
 * Only the registrable domain is sent, never the full URL, and the answer is
 * cached for a week: one lookup per domain, not per link. A failure is not a
 * danger (D-126), so it returns null and the inspection continues.
 */
final class RdapClient
{
    public function ageDays(?string $registrable): ?int
    {
        if ($registrable === null || $registrable === '') {
            return null;
        }

        $key = 'abuse:rdap:'.$registrable;
        $cached = Cache::get($key);

        if (is_int($cached)) {
            return $cached < 0 ? null : $cached;
        }

        $age = $this->fetch($registrable);

        Cache::put(
            $key,
            $age ?? -1,
            $age === null
                ? now()->addHour()
                : now()->addDays((int) config('abuse.rdap.cache_days', 7)),
        );

        return $age;
    }

    private function fetch(string $registrable): ?int
    {
        $base = (string) config('abuse.rdap.url');

        try {
            $response = Http::timeout((int) config('abuse.timeouts.rdap', 3))
                ->acceptJson()
                ->get(rtrim($base, '/').'/'.$registrable);

            if (! $response->successful()) {
                return null;
            }

            /** @var array<string, mixed> $payload */
            $payload = $response->json();
            $events = $payload['events'] ?? [];

            if (! is_array($events)) {
                return null;
            }

            foreach ($events as $event) {
                if (! is_array($event) || ($event['eventAction'] ?? null) !== 'registration') {
                    continue;
                }

                $date = $event['eventDate'] ?? null;

                if (! is_string($date) || $date === '') {
                    continue;
                }

                $registered = CarbonImmutable::parse($date)->utc();

                return max(0, (int) $registered->diffInDays(CarbonImmutable::now('UTC')));
            }
        } catch (Throwable $exception) {
            report($exception);
        }

        return null;
    }
}
