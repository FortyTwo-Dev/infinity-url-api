<?php

declare(strict_types=1);

namespace App\Actions\Links;

use App\Models\Link;
use App\Models\Workspace;
use App\Support\Cursor;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;
use Throwable;

final class ListLinks
{
    /**
     * @param  array<string, mixed>  $filters
     * @return array{data: Collection<int, Link>, next_cursor: string|null, has_more: bool}
     */
    public function handle(Workspace $workspace, array $filters): array
    {
        $limit = (int) ($filters['limit'] ?? 20);
        $sort = (string) ($filters['sort'] ?? '-created_at');
        $descending = str_starts_with($sort, '-');

        $query = $workspace->links()->with('domain');

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

        $query->orderBy('created_at', $descending ? 'desc' : 'asc')
            ->orderBy('id', $descending ? 'desc' : 'asc');

        if (isset($filters['cursor'])) {
            $cursor = Cursor::decode((string) $filters['cursor']);
            $created = $cursor['created_at'] ?? null;
            $id = $cursor['id'] ?? null;

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

        $links = $query->limit($limit + 1)->get();
        $hasMore = $links->count() > $limit;
        $page = $links->take($limit);
        $last = $page->last();

        return [
            'data' => new Collection($page->all()),
            'next_cursor' => $hasMore && $last !== null
                ? Cursor::encode([
                    'created_at' => $last->created_at?->toISOString(),
                    'id' => $last->id,
                ])
                : null,
            'has_more' => $hasMore,
        ];
    }
}
