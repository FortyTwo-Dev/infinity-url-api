<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Domain;
use App\Models\Link;
use Closure;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class SlugGenerator
{
    public const ALPHABET = 'abcdefghijkmnopqrstuvwxyz23456789';

    public const LENGTH = 7;

    private readonly Closure $randomIndex;

    public function __construct(
        private readonly ReservedSlugs $reserved,
        ?Closure $randomIndex = null,
    ) {
        $this->randomIndex = $randomIndex ?? static fn (int $max): int => random_int(0, $max - 1);
    }

    public function generate(Domain $domain): string
    {
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $slug = $this->candidate();

            if (! $this->isTaken($domain, $slug)) {
                return $slug;
            }
        }

        throw new RuntimeException('Unable to generate a free slug.');
    }

    private function candidate(): string
    {
        $max = strlen(self::ALPHABET);
        $slug = '';

        for ($position = 0; $position < self::LENGTH; $position++) {
            $index = ($this->randomIndex)($max);
            $slug .= self::ALPHABET[$index];
        }

        return $slug;
    }

    private function isTaken(Domain $domain, string $slug): bool
    {
        if ($this->reserved->exists($slug)) {
            return true;
        }

        if (DB::table('slug_tombstones')
            ->where('domain_id', $domain->id)
            ->where('slug', $slug)
            ->exists()) {
            return true;
        }

        return Link::withTrashed()
            ->where('domain_id', $domain->id)
            ->where('slug', $slug)
            ->exists();
    }
}
