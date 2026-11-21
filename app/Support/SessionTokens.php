<?php

declare(strict_types=1);

namespace App\Support;

final readonly class SessionTokens
{
    public function __construct(
        public string $accessToken,
        public string $refreshToken,
        public string $familyId,
        public int $expiresIn,
    ) {}
}
