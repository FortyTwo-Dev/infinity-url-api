<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\DB;

final class ReservedSlugs
{
    public function exists(string $slug): bool
    {
        return DB::table('reserved_slugs')
            ->where('slug', mb_strtolower(trim($slug)))
            ->exists();
    }
}
