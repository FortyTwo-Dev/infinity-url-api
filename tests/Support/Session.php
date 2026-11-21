<?php

declare(strict_types=1);

namespace Tests\Support;

final class Session
{
    public function __construct(
        public readonly string $accessToken,
        public readonly string $cookie,
    ) {}
}
