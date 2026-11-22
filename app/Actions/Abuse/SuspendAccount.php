<?php

declare(strict_types=1);

namespace App\Actions\Abuse;

use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Suspension blocks link creation and nothing else: the session, the export
 * and the API tokens stay usable, so the owner can retrieve their data
 * (docs/04 §4.3, D-125 keeps four messages: no fifth email here).
 */
final class SuspendAccount
{
    public function handle(User $user): void
    {
        if ($user->isSuspended()) {
            return;
        }

        $user->suspended_at = CarbonImmutable::now('UTC');
        $user->save();
    }
}
