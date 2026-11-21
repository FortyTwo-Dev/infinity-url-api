<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockProvider;
use Throwable;

final class RedirectCache
{
    public function __construct(private readonly Repository $cache) {}

    public static function key(string $host, string $slug): string
    {
        return 'link:'.$host.':'.$slug;
    }

    /**
     * @return array<array-key, mixed>|null
     */
    public function get(string $host, string $slug): ?array
    {
        try {
            $value = $this->cache->get(self::key($host, $slug));
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }

        return is_array($value) ? $value : null;
    }

    /**
     * @param  array<array-key, mixed>  $payload
     */
    public function put(string $host, string $slug, array $payload, int $ttl): void
    {
        try {
            $this->cache->put(self::key($host, $slug), $payload, $ttl);
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    public function forget(string $host, string $slug): void
    {
        try {
            $this->cache->forget(self::key($host, $slug));
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    public function acquire(string $host, string $slug): ?Lock
    {
        try {
            return $this->waitForLock($host, $slug);
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }
    }

    private function waitForLock(string $host, string $slug): ?Lock
    {
        $store = $this->cache->getStore();

        if (! $store instanceof LockProvider) {
            return null;
        }

        $lock = $store->lock(
            self::key($host, $slug).':lock',
            (int) config('redirect.lock.seconds', 10),
        );

        $deadline = microtime(true) + (float) config('redirect.lock.wait', 5);

        while (! $lock->get()) {
            if (microtime(true) >= $deadline) {
                return null;
            }

            usleep(50_000);
        }

        return $lock;
    }

    public function release(Lock $lock): void
    {
        try {
            $lock->release();
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
