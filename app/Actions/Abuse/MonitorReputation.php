<?php

declare(strict_types=1);

namespace App\Actions\Abuse;

use App\Models\BlocklistDomain;
use App\Models\Domain;
use App\Services\Abuse\Ntfy;
use App\Services\Abuse\PublicSuffix;

/**
 * D-113, reduced to the sources the product actually keeps: the local dumps.
 * It checks our own names only — the link domain and the client domains — and
 * never sends a client URL anywhere. Safe Browsing was removed by D-97, and
 * Spamhaus is not queried: being on the local dumps is the signal we can act
 * on without a new third party.
 */
final class MonitorReputation
{
    public function __construct(
        private readonly PublicSuffix $suffix,
        private readonly Ntfy $ntfy,
    ) {}

    /**
     * @return array{domains: list<string>, hits: list<string>}
     */
    public function handle(): array
    {
        $domains = collect([(string) config('redirect.host')])
            ->merge(Domain::query()->pluck('hostname'))
            ->map(static fn (string $host): string => strtolower($host))
            ->unique()
            ->values()
            ->all();

        $hits = [];

        foreach ($domains as $domain) {
            $registrable = $this->suffix->registrable($domain);

            if ($registrable !== null && BlocklistDomain::query()->whereKey($registrable)->exists()) {
                $hits[] = $domain;
            }
        }

        if ($hits !== []) {
            $this->ntfy->send('Domain on an abuse blocklist', implode("\n", [
                'Domains: '.implode(', ', $hits),
                'Source: local abuse dumps',
            ]));
        }

        return ['domains' => $domains, 'hits' => $hits];
    }
}
