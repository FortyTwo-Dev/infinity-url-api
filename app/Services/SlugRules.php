<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\ProblemException;
use App\Models\Domain;
use App\Models\Link;
use Illuminate\Support\Facades\DB;

final class SlugRules
{
    public const PATTERN = '/^[a-z0-9_-]{2,64}$/';

    public function __construct(private readonly ReservedSlugs $reserved) {}

    public static function normalize(string $slug): string
    {
        return mb_strtolower(trim($slug));
    }

    public static function isValid(string $slug): bool
    {
        return preg_match(self::PATTERN, self::normalize($slug)) === 1;
    }

    public function assertAvailable(Domain $domain, string $slug, ?int $ignoreLinkId = null): void
    {
        $slug = self::normalize($slug);

        if ($this->reserved->exists($slug)) {
            throw ProblemException::slugReserved($slug);
        }

        if (DB::table('slug_tombstones')
            ->where('domain_id', $domain->id)
            ->where('slug', $slug)
            ->exists()) {
            throw ProblemException::slugTaken($slug);
        }

        $query = Link::withTrashed()
            ->where('domain_id', $domain->id)
            ->where('slug', $slug);

        if ($ignoreLinkId !== null) {
            $query->whereKeyNot($ignoreLinkId);
        }

        if ($query->exists()) {
            throw ProblemException::slugTaken($slug);
        }
    }
}
