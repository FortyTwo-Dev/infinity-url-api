<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\DomainStatus;
use App\Enums\LinkStatus;
use App\Exceptions\RedirectUnavailableException;
use App\Models\Domain;
use App\Models\Link;
use App\Support\RedirectResult;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Database\LostConnectionException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PDOException;

final class ResolveLink
{
    private const DATABASE_UNAVAILABLE = 'The database is unavailable.';

    public function __construct(private readonly RedirectCache $cache) {}

    public function resolve(string $host, string $slug): RedirectResult
    {
        $slug = strtolower($slug);

        $result = $this->cached($host, $slug);

        if ($result === null) {
            $lock = $this->cache->acquire($host, $slug);

            $result = $lock === null
                ? $this->cold($host, $slug)
                : $this->resolveUnderLock($host, $slug, $lock);
        }

        return $result;
    }

    private function resolveUnderLock(string $host, string $slug, Lock $lock): RedirectResult
    {
        try {
            return $this->cached($host, $slug) ?? $this->cold($host, $slug);
        } finally {
            $this->cache->release($lock);
        }
    }

    private function cached(string $host, string $slug): ?RedirectResult
    {
        $payload = $this->cache->get($host, $slug);

        if ($payload === null) {
            return null;
        }

        $result = RedirectResult::tryFromArray($payload);

        if ($result === null || ! $result->isValid()) {
            $this->cache->forget($host, $slug);

            return null;
        }

        return $result;
    }

    private function cold(string $host, string $slug): RedirectResult
    {
        $result = $this->lookup($host, $slug);
        $ttl = $this->ttlFor($result);

        if ($ttl > 0) {
            $this->cache->put($host, $slug, $result->toArray(), $ttl);
        }

        return $result;
    }

    private function lookup(string $host, string $slug): RedirectResult
    {
        try {
            return $this->lookupFromDatabase($host, $slug);
        } catch (LostConnectionException $exception) {
            throw new RedirectUnavailableException(self::DATABASE_UNAVAILABLE, previous: $exception);
        } catch (QueryException $exception) {
            if (! $this->isUnavailable($exception)) {
                throw $exception;
            }

            throw new RedirectUnavailableException(self::DATABASE_UNAVAILABLE, previous: $exception);
        } catch (PDOException $exception) {
            if (! $this->isUnavailable($exception)) {
                throw $exception;
            }

            throw new RedirectUnavailableException(self::DATABASE_UNAVAILABLE, previous: $exception);
        }
    }

    private function isUnavailable(PDOException $exception): bool
    {
        if (preg_match('/SQLSTATE\[([0-9A-Z]{5})\]/', $exception->getMessage(), $matches) !== 1) {
            return false;
        }

        $sqlState = $matches[1];

        return str_starts_with($sqlState, '08') || str_starts_with($sqlState, '57P');
    }

    private function lookupFromDatabase(string $host, string $slug): RedirectResult
    {
        $domain = Domain::query()
            ->where('hostname', $host)
            ->where('status', DomainStatus::Active)
            ->whereNotNull('verified_at')
            ->first();

        if ($domain === null) {
            return RedirectResult::notFound();
        }

        $link = Link::withTrashed()
            ->where('domain_id', $domain->id)
            ->where('slug', $slug)
            ->first();

        if ($link === null) {
            return $this->missingResult($domain, $slug);
        }

        return match (true) {
            $link->trashed() => RedirectResult::goneDeleted(),
            $link->status === LinkStatus::Blocked => RedirectResult::blocked(),
            $link->status === LinkStatus::Disabled => RedirectResult::goneExpired(),
            $link->expires_at !== null && ! $link->expires_at->isFuture() => RedirectResult::goneExpired(),
            default => RedirectResult::redirect($link->destination, $link->expires_at, (int) $link->id),
        };
    }

    private function missingResult(Domain $domain, string $slug): RedirectResult
    {
        $erased = DB::table('slug_tombstones')
            ->where('domain_id', $domain->id)
            ->where('slug', $slug)
            ->exists();

        return $erased ? RedirectResult::goneDeleted() : RedirectResult::notFound();
    }

    private function ttlFor(RedirectResult $result): int
    {
        return match ($result->status) {
            302 => $this->redirectTtl($result),
            404 => (int) config('redirect.ttl.not_found', 60),
            403 => (int) config('redirect.ttl.blocked', 60),
            410 => $result->reason === 'deleted'
                ? (int) config('redirect.ttl.deleted', 86_400)
                : (int) config('redirect.ttl.expired', 60),
            default => (int) config('redirect.ttl.not_found', 60),
        };
    }

    private function redirectTtl(RedirectResult $result): int
    {
        $default = (int) config('redirect.ttl.redirect', 86_400);

        if ($result->expiresAt === null) {
            return $default;
        }

        $seconds = (int) now()->diffInSeconds($result->expiresAt, false);

        return max(0, min($default, $seconds));
    }
}
