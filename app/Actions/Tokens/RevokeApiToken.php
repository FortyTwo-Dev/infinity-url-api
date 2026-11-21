<?php

declare(strict_types=1);

namespace App\Actions\Tokens;

use App\Models\PersonalAccessToken;

final class RevokeApiToken
{
    public function handle(PersonalAccessToken $token): void
    {
        $token->delete();
    }
}
