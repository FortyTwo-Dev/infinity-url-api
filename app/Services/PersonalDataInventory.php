<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Link;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Analytics\StatsReader;
use App\Support\Analytics\RetentionWindow;
use App\Support\ApiData;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;

final class PersonalDataInventory
{
    public function __construct(private readonly StatsReader $stats) {}

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
            'statistics' => $workspace === null
                ? []
                : $this->stats->export($workspace, $this->retentionStart()),
        ];
    }

    private function retentionStart(): CarbonImmutable
    {
        $days = (int) config('analytics.retention_days', 30);

        return RetentionWindow::purgeBefore(CarbonImmutable::now('UTC'), $days);
    }
}
