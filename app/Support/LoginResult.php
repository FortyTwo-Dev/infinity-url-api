<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\User;

final readonly class LoginResult
{
    private function __construct(
        public string $status,
        public ?SessionTokens $tokens = null,
        public ?string $challenge = null,
        public ?User $user = null,
    ) {}

    public static function authenticated(SessionTokens $tokens, User $user): self
    {
        return new self('authenticated', $tokens, null, $user);
    }

    public static function totpRequired(string $challenge): self
    {
        return new self('totp_required', null, $challenge, null);
    }
}
