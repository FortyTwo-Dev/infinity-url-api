<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Models\Link;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class PurgeDeletedUsers
{
    public function handle(): int
    {
        $users = User::onlyTrashed()
            ->where('deleted_at', '<=', now()->subDays(30))
            ->get();

        $count = 0;

        foreach ($users as $user) {
            $this->purge($user);
            $count++;
        }

        return $count;
    }

    public function purge(User $user): void
    {
        DB::transaction(function () use ($user): void {
            $workspaces = $user->workspaces()->get();

            foreach ($workspaces as $workspace) {
                $links = Link::withTrashed()
                    ->where('workspace_id', $workspace->id)
                    ->get();

                foreach ($links as $link) {
                    DB::table('slug_tombstones')->insertOrIgnore([
                        'domain_id' => $link->domain_id,
                        'slug' => $link->slug,
                        'erased_at' => now(),
                    ]);

                    // Force-delete through Eloquent so the observer clears the
                    // warmed redirect cache before the workspace cascade.
                    $link->forceDelete();
                }

                $workspace->forceDelete();
            }

            $user->tokens()->delete();
            $user->recoveryCodes()->delete();
            $user->forceDelete();
        });
    }
}
