<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\ProblemException;
use App\Models\PersonalAccessToken;
use App\Models\User;

final class TokenLocator
{
    public function __construct(private readonly ApiTokenService $tokens) {}

    public function for(User $user, int $id): PersonalAccessToken
    {
        $token = $this->tokens->apiTokens($user)->whereKey($id)->first();

        if ($token === null) {
            throw ProblemException::notFound();
        }

        return $token;
    }
}
