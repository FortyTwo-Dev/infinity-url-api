<?php

declare(strict_types=1);

namespace App\Actions\Links;

use App\Models\Link;
use App\Models\Workspace;
use App\Support\Cursor;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

final class ListLinks
{
    /**
     * @param  array<string, mixed>  $filters
     * @return array{data: Collection<int, Link>, next_cursor: string|null, has_more: bool, clicks_7d: array<string, int>|null}
     */
    public function handle(Workspace $workspace, array $filters): array
    {
        $limit = (int) ($filters['limit'] ?? 20);
        $sort = (string) ($filters['sort'] ?? '-created_at');
        $descending = str_starts_with($sort, '-');

        $query = $workspace->links()->with('domain')->getQuery();
        $this->applyFilters($query, $filters);
        $this->applyOrder($query, $descending);
        $this->applyCursor(
            $query,
            isset($filters['cursor']) ? (string) $filters['cursor'] : null,
            $descending,
        );

        $links = $query->limit($limit + 1)->get();
        $hasMore = $links->count() > $limit;
        $page = $links->take($limit);
        $last = $page->last();
        $clicks = ($filters['include'] ?? null) === 'clicks_7d'
            ? $this->sevenDayClicks($page)
            : null;

        return [
            'data' => new Collection($page->all()),
            'next_cursor' => $hasMore && $last !== null
                ? Cursor::encode([
                    'created_at' => $last->created_at?->toISOString(),
                    'id' => $last->id,
                ])
                : null,
            'has_more' => $hasMore,
            'clicks_7d' => $clicks,
        ];
    }

    /**
     * @param  Builder<Link>  $query
     * @param  array<string, mixed>  $filters
     */
    private function applyFilters(Builder $query, array $filters): void
    {
        if (isset($filters['domain'])) {
            $query->whereHas('domain', static function ($domain) use ($filters): void {
                $domain->where('hostname', $filters['domain']);
            });
        }

        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (isset($filters['q'])) {
            $needle = '%'.$filters['q'].'%';

            $query->where(static function ($search) use ($needle): void {
                $search->where('slug', 'ilike', $needle)
                    ->orWhere('destination', 'ilike', $needle);
            });
        }
    }

    /**
     * @param  Builder<Link>  $query
     */
    private function applyOrder(Builder $query, bool $descending): void
    {
        $query->orderBy('created_at', $descending ? 'desc' : 'asc')
            ->orderBy('id', $descending ? 'desc' : 'asc');
    }

    /**
     * @param  Builder<Link>  $query
     */
    private function applyCursor(
        Builder $query,
        ?string $cursor,
        bool $descending,
    ): void {
        if ($cursor === null) {
            return;
        }

        $decoded = Cursor::decode($cursor);
        $created = $decoded['created_at'] ?? null;
        $id = $decoded['id'] ?? null;

        if (! is_string($created) || ! is_numeric($id)) {
            throw ValidationException::withMessages([
                'cursor' => ['The cursor is invalid.'],
            ]);
        }

        try {
            $createdAt = CarbonImmutable::parse($created);
        } catch (Throwable) {
            throw ValidationException::withMessages([
                'cursor' => ['The cursor is invalid.'],
            ]);
        }

        $operator = $descending ? '<' : '>';

        $query->where(static function ($after) use ($createdAt, $id, $operator): void {
            $after->where('created_at', $operator, $createdAt)
                ->orWhere(static function ($sameDate) use ($createdAt, $id, $operator): void {
                    $sameDate->where('created_at', $createdAt)
                        ->where('id', $operator, (int) $id);
                });
        });
    }

    /**
     * One aggregate query for the whole page. A link without a row in the
     * window is reported as zero, never omitted from the map.
     *
     * @param  Collection<int, Link>  $page
     * @return array<string, int> keyed by public id
     */
    private function sevenDayClicks(Collection $page): array
    {
        $internalIds = [];
        $publicIds = [];

        foreach ($page as $link) {
            $id = (int) $link->getKey();
            $internalIds[] = $id;
            $publicIds[$id] = $link->public_id;
        }

        if ($internalIds === []) {
            return [];
        }

        $today = CarbonImmutable::now('UTC')->startOfDay();

        $counts = DB::table('link_stats_daily')
            ->whereIn('link_id', $internalIds)
            ->whereBetween('date', [$today->subDays(6)->toDateString(), $today->toDateString()])
            ->groupBy('link_id')
            ->select('link_id', DB::raw('sum(clicks) as clicks'))
            ->pluck('clicks', 'link_id');

        $clicks = [];

        foreach ($internalIds as $id) {
            $clicks[$publicIds[$id]] = (int) ($counts[$id] ?? 0);
        }

        return $clicks;
    }
}
