<?php

declare(strict_types=1);

namespace Tests\Support\Abuse;

use App\Services\Abuse\TlsProbe;
use Carbon\CarbonImmutable;

final class FakeTlsProbe implements TlsProbe
{
    /** @var list<array{host: string, address: string}> */
    public array $calls = [];

    public function __construct(private readonly ?CarbonImmutable $issuedAt = null) {}

    public function issuedAt(string $host, string $address): ?CarbonImmutable
    {
        $this->calls[] = ['host' => $host, 'address' => $address];

        return $this->issuedAt;
    }
}
