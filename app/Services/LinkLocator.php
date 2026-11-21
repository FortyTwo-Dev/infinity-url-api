<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\ProblemException;
use App\Models\Link;
use App\Models\Workspace;

final class LinkLocator
{
    public function for(Workspace $workspace, string $publicId): Link
    {
        $link = $workspace->links()
            ->where('public_id', $publicId)
            ->first();

        if ($link === null) {
            throw ProblemException::linkNotFound();
        }

        return $link;
    }
}
