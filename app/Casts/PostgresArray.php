<?php

declare(strict_types=1);

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * PostgreSQL `text[]`, which PDO returns as the literal `{a,b}`.
 *
 * The alternative would be jsonb, but the schema fixes `text[]`: an array is
 * the natural shape for a list of sources, and the merge happens in SQL.
 *
 * @implements CastsAttributes<list<string>, list<string>>
 */
final class PostgresArray implements CastsAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     * @return list<string>
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): array
    {
        if (is_array($value)) {
            return array_values(array_map(static fn (mixed $item): string => (string) $item, $value));
        }

        if (! is_string($value) || $value === '' || $value === '{}') {
            return [];
        }

        $inner = str_starts_with($value, '{') ? substr($value, 1, -1) : $value;
        $items = [];

        preg_match_all('/"((?:[^"\\\\]|\\\\.)*)"|([^,]+)/', $inner, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            $items[] = isset($match[1]) && $match[1] !== ''
                ? stripcslashes($match[1])
                : trim($match[2] ?? '');
        }

        return $items;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        $items = array_map(
            static fn (mixed $item): string => '"'.str_replace(
                ['\\', '"'],
                ['\\\\', '\\"'],
                (string) $item,
            ).'"',
            (array) $value,
        );

        return '{'.implode(',', $items).'}';
    }
}
