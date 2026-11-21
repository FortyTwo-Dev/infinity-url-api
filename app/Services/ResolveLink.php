<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\DomainStatus;
use App\Enums\LinkStatus;
use App\Exceptions\RedirectUnavailableException;
use App\Models\Domain;
use App\Models\Link;
use App\Support\RedirectResult;
use Illuminate\Database\LostConnectionException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PDOException;

final class ResolveLink
{
    public function __construct(private readonly RedirectCache $cache) {}

    public function resolve(string $host, string $slug): RedirectResult
    {
        $slug = strtolower($slug);

        $cached = $this->cached($host, $slug);

        if ($cached !== null) {
            return $cached;
        }

        $lock = $this->cache->acquire($host, $slug);

        if ($lock === null) {
            return $this->cold($host, $slug);
        }

        try {
            $cached = $this->cached($host, $slug);

            if ($cached !== null) {
                return $cached;
            }

            return $this->cold($host, $slug);
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
            throw new RedirectUnavailableException('The database is unavailable.', previous: $exception);
        } catch (QueryException $exception) {
            if (! $this->isUnavailable($exception)) {
                throw $exception;
            }

            throw new RedirectUnavailableException('The database is unavailable.', previous: $exception);
        } catch (PDOException $exception) {
            if (! $this->isUnavailable($exception)) {
                throw $exception;
            }

            throw new RedirectUnavailableException('The database is unavailable.', previous: $exception);
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
            $erased = DB::table('slug_tombstones')
                ->where('domain_id', $domain->id)
                ->where('slug', $slug)
                ->exists();

            return $erased ? RedirectResult::goneDeleted() : RedirectResult::notFound();
        }

        if ($link->trashed()) {
            return RedirectResult::goneDeleted();
        }

        if ($link->status === LinkStatus::Blocked) {
            return RedirectResult::blocked();
        }

        if ($link->status === LinkStatus::Disabled) {
            return RedirectResult::goneExpired();
        }

        if ($link->expires_at !== null && ! $link->expires_at->isFuture()) {
            return RedirectResult::goneExpired();
        }

        return RedirectResult::redirect($link->destination, $link->expires_at);
    }

    private function ttlFor(RedirectResult $result): int
    {
        if ($result->status === 302) {
            $default = (int) config('redirect.ttl.redirect', 86_400);

            if ($result->expiresAt === null) {
                return $default;
            }

            $seconds = (int) now()->diffInSeconds($result->expiresAt, false);

            return max(0, min($default, $seconds));
        }

        if ($result->status === 404) {
            return (int) config('redirect.ttl.not_found', 60);
        }

        if ($result->status === 403) {
            return (int) config('redirect.ttl.blocked', 60);
        }

        if ($result->status === 410) {
            return $result->reason === 'deleted'
                ? (int) config('redirect.ttl.deleted', 86_400)
                : (int) config('redirect.ttl.expired', 60);
        }

        return (int) config('redirect.ttl.not_found', 60);
    }
}
