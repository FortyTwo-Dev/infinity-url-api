<?php

declare(strict_types=1);

namespace App\Services\Abuse;

final class ProbeResult
{
    public function __construct(
        public readonly int $status,
        public readonly int $redirects = 0,
        public readonly bool $privateHop = false,
        public readonly ?string $address = null,
    ) {}

    public function reachable(): bool
    {
        return $this->status > 0;
    }
}
