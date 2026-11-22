<?php

declare(strict_types=1);

namespace App\Actions\Abuse;

use App\Services\Abuse\DumpFetcher;
use App\Services\Abuse\DumpParser;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Daily blocklist refresh: download each dump in full, upsert the registrable
 * domains, then purge what no dump has listed for the retention window.
 *
 * `sources` is merged, never overwritten: an entry keeps every dump that ever
 * listed it, and the operator can see where a block would come from.
 */
final class RefreshBlocklists
{
    public function __construct(
        private readonly DumpFetcher $fetcher,
        private readonly DumpParser $parser,
    ) {}

    /**
     * @return array{sources: array<string, int>, domains: int, purged: int, failed: list<string>}
     */
    public function handle(?CarbonImmutable $today = null): array
    {
        $today ??= CarbonImmutable::today('UTC');
        $date = $today->toDateString();

        $perSource = [];
        $failed = [];

        foreach (array_keys((array) config('abuse.sources', [])) as $source) {
            $lines = $this->fetcher->fetch($source);

            if ($lines === null) {
                $failed[] = $source;

                continue;
            }

            $domains = $this->parser->domains($lines);
            $perSource[$source] = count($domains);

            $this->upsert($source, array_keys($domains), $date);
        }

        $purged = $perSource === [] ? 0 : DB::table('blocklist_domains')
            ->where('last_seen', '<', $today->subDays((int) config('abuse.blocklist.retention_days', 30))->toDateString())
            ->delete();

        return [
            'sources' => $perSource,
            'domains' => array_sum($perSource),
            'purged' => $purged,
            'failed' => $failed,
        ];
    }

    /**
     * @param  list<string>  $domains
     */
    private function upsert(string $source, array $domains, string $date): void
    {
        foreach (array_chunk($domains, 500) as $chunk) {
            $values = [];
            $bindings = [];

            foreach ($chunk as $domain) {
                $values[] = '(?, ?::text[], ?::date, ?::date)';
                $bindings[] = $domain;
                $bindings[] = '{'.$source.'}';
                $bindings[] = $date;
                $bindings[] = $date;
            }

            DB::statement(
                'insert into blocklist_domains (domain, sources, first_seen, last_seen) values '
                .implode(', ', $values)
                .' on conflict (domain) do update set '
                .'sources = array(select distinct unnest(blocklist_domains.sources || excluded.sources) order by 1), '
                .'last_seen = excluded.last_seen',
                $bindings,
            );
        }
    }
}
