<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\ProblemException;
use App\Models\User;
use App\Models\Workspace;

final class WorkspaceContext
{
    public function for(User $user): Workspace
    {
        $workspace = $user->workspace();

        if ($workspace === null) {
            throw ProblemException::notFound();
        }

        return $workspace;
    }
}
