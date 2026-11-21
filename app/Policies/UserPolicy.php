<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;

final class UserPolicy
{
    public function update(User $user, User $target): bool
    {
        return $user->id === $target->id;
    }

    public function delete(User $user, User $target): bool
    {
        return $user->id === $target->id;
    }
}
