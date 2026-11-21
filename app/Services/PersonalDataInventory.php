<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Link;
use App\Models\User;
use App\Models\Workspace;
use App\Support\ApiData;
use Illuminate\Database\Eloquent\Collection;

final class PersonalDataInventory
{
    /**
     * Allowlist only. A future column never leaks by omission.
     *
     * @return array<string, mixed>
     */
    public function export(User $user): array
    {
        $workspace = $user->workspace();
        $links = $workspace instanceof Workspace
            ? $workspace->links()->with('domain')->orderBy('created_at')->get()
            : new Collection;

        return [
            'account' => [
                ...ApiData::user($user),
                'created_at' => ApiData::date($user->created_at),
            ],
            'workspace' => $workspace === null ? null : ApiData::workspace($workspace),
            'links' => $links->map(static fn (Link $link): array => ApiData::link($link))->all(),
            'statistics' => [],
        ];
    }
}
