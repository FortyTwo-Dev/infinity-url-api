<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\PersonalAccessToken;
use App\Models\User;

final class PersonalAccessTokenPolicy
{
    public function delete(User $user, PersonalAccessToken $token): bool
    {
        return $token->tokenable_id === $user->id
            && $token->tokenable_type === $user->getMorphClass()
            && $token->type === 'access'
            && $token->abilities !== ['*'];
    }
}
