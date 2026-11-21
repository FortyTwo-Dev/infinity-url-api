<?php

declare(strict_types=1);

namespace App\Actions\Tokens;

use App\Models\PersonalAccessToken;
use App\Models\User;
use App\Services\ApiTokenService;
use App\Support\Cursor;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

final class ListApiTokens
{
    public function __construct(private readonly ApiTokenService $tokens) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return array{data: Collection<int, PersonalAccessToken>, next_cursor: string|null, has_more: bool}
     */
    public function handle(User $user, array $filters): array
    {
        $limit = (int) ($filters['limit'] ?? 20);

        $query = $this->tokens->apiTokens($user)->orderByDesc('id');

        if (isset($filters['cursor'])) {
            $cursor = Cursor::decode((string) $filters['cursor']);
            $id = $cursor['id'] ?? null;

            if (! is_numeric($id)) {
                throw ValidationException::withMessages([
                    'cursor' => ['The cursor is invalid.'],
                ]);
            }

            $query->where('id', '<', (int) $id);
        }

        $tokens = $query->limit($limit + 1)->get();
        $hasMore = $tokens->count() > $limit;
        $page = $tokens->take($limit);
        $last = $page->last();

        return [
            'data' => new Collection($page->all()),
            'next_cursor' => $hasMore && $last !== null
                ? Cursor::encode(['id' => $last->id])
                : null,
            'has_more' => $hasMore,
        ];
    }
}
