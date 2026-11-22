<?php

declare(strict_types=1);

namespace App\Services\Abuse;

use App\Models\BlocklistDomain;

/**
 * A local lookup, never an API call: the domain of a client's link never
 * leaves the server (D-96).
 */
final class Blocklist
{
    public function __construct(private readonly PublicSuffix $suffix) {}

    public function hits(string $host): bool
    {
        $registrable = $this->suffix->registrable($host);

        if ($registrable === null) {
            return false;
        }

        return BlocklistDomain::query()->whereKey($registrable)->exists();
    }

    public function registrable(string $host): ?string
    {
        return $this->suffix->registrable($host);
    }
}
