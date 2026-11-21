<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Link;
use App\Models\User;

final class LinkPolicy
{
    public function create(User $user): bool
    {
        return $user->workspace() !== null;
    }

    public function view(User $user, Link $link): bool
    {
        return $this->owns($user, $link);
    }

    public function update(User $user, Link $link): bool
    {
        return $this->owns($user, $link);
    }

    public function delete(User $user, Link $link): bool
    {
        return $this->owns($user, $link);
    }

    private function owns(User $user, Link $link): bool
    {
        $workspace = $user->workspace();

        return $workspace !== null && $link->workspace_id === $workspace->id;
    }
}
