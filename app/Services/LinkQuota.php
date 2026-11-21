<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\LinkStatus;
use App\Exceptions\ProblemException;
use App\Models\Link;
use App\Models\Workspace;

final class LinkQuota
{
    /**
     * The caller must already hold a row lock on the workspace.
     */
    public function assertRoom(Workspace $workspace): void
    {
        $limit = (int) config('identity.limits.active_links');

        $active = Link::query()
            ->where('workspace_id', $workspace->id)
            ->where('status', LinkStatus::Active)
            ->whereNull('deleted_at')
            ->where(static function ($query): void {
                $query->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            })
            ->count();

        if ($active >= $limit) {
            throw ProblemException::quotaExceeded($limit);
        }
    }
}
