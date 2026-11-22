<?php

declare(strict_types=1);

namespace Tests\Support\Abuse;

use App\Services\Abuse\DnsResolver;

final class FakeDnsResolver implements DnsResolver
{
    /** @var list<string> */
    public array $calls = [];

    /**
     * @param  array<string, list<string>>  $map
     */
    public function __construct(private readonly array $map = []) {}

    public function addresses(string $host): array
    {
        $this->calls[] = $host;

        if (array_key_exists($host, $this->map)) {
            return $this->map[$host];
        }

        return ['93.184.216.34'];
    }
}
