<?php

declare(strict_types=1);

namespace App\Services\Abuse;

/**
 * DNS is the only part of the check that cannot be faked by `Http::fake`.
 * Keeping it behind a contract lets the tests stay offline and deterministic.
 */
interface DnsResolver
{
    /**
     * @return list<string> every A and AAAA address, or an empty list on failure
     */
    public function addresses(string $host): array;
}
