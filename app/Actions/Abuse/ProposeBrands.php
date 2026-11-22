<?php

declare(strict_types=1);

namespace App\Actions\Abuse;

use App\Models\BrandProposal;
use App\Models\ProtectedBrand;
use App\Services\Abuse\DumpFetcher;
use App\Services\Abuse\DumpParser;
use App\Services\Abuse\PublicSuffix;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Reads the phishing dumps and proposes the labels that are actually being
 * imitated. A human accepts them; nothing here ever writes to
 * `protected_brands` (D-132).
 */
final class ProposeBrands
{
    /** Labels that decorate a phishing URL but name no brand. */
    private const GENERIC = [
        'www', 'web', 'webmail', 'mail', 'smtp', 'login', 'signin', 'signup', 'secure', 'security',
        'account', 'accounts', 'verify', 'verification', 'auth', 'authenticate', 'support', 'service',
        'services', 'help', 'client', 'clients', 'compte', 'moncompte', 'connexion', 'connection',
        'espace', 'portail', 'portal', 'app', 'apps', 'api', 'cdn', 'static', 'assets', 'img',
        'images', 'media', 'm', 'mobile', 'fr', 'en', 'com', 'net', 'org', 'info', 'online',
        'site', 'shop', 'store', 'pay', 'payment', 'paiement', 'facture', 'invoice',
    ];

    public function __construct(
        private readonly DumpFetcher $fetcher,
        private readonly DumpParser $parser,
        private readonly PublicSuffix $suffix,
    ) {}

    /**
     * @param  list<string>|null  $lines  the phishing dump lines, or null to download them
     * @return array{proposed: int, failed: list<string>}
     */
    public function handle(?CarbonImmutable $today = null, ?array $lines = null): array
    {
        $today ??= CarbonImmutable::today('UTC');
        $failed = [];

        if ($lines === null) {
            $lines = [];

            foreach (['phishing_army', 'openphish'] as $source) {
                $fetched = $this->fetcher->fetch($source);

                if ($fetched === null) {
                    $failed[] = $source;

                    continue;
                }

                $lines = [...$lines, ...$fetched];
            }
        }

        $counts = $this->count($lines);
        $known = $this->knownTokens();
        $minimum = (int) config('abuse.proposals.min_occurrences', 2);
        $proposed = 0;

        foreach ($counts as $token => $occurrences) {
            if ($occurrences < $minimum || isset($known[$token])) {
                continue;
            }

            DB::table('brand_proposals')->upsert([[
                'token' => $token,
                'occurrences' => $occurrences,
                'window_from' => $today->subDays(6)->toDateString(),
                'window_to' => $today->toDateString(),
                'state' => BrandProposal::PROPOSED,
                'created_at' => $today->toDateTimeString(),
            ]], ['token', 'window_from', 'window_to'], ['occurrences']);

            $proposed++;
        }

        return ['proposed' => $proposed, 'failed' => $failed];
    }

    /**
     * @param  list<string>  $lines
     * @return array<string, int>
     */
    private function count(array $lines): array
    {
        $counts = [];

        foreach ($lines as $line) {
            $domain = $this->parser->registrable($line);

            if ($domain === null) {
                continue;
            }

            $labels = $this->suffix->labels($domain);
            $registrableLabels = count($labels);

            $host = $this->host($line);

            if ($host === null) {
                continue;
            }

            $hostLabels = $this->suffix->labels($host);
            $before = array_slice($hostLabels, 0, count($hostLabels) - $registrableLabels);

            foreach ($before as $label) {
                if (! preg_match('/^[a-z][a-z0-9-]{2,29}$/', $label) || in_array($label, self::GENERIC, true)) {
                    continue;
                }

                $counts[$label] = ($counts[$label] ?? 0) + 1;
            }
        }

        arsort($counts);

        return $counts;
    }

    /**
     * @return array<string, true>
     */
    private function knownTokens(): array
    {
        $known = [];

        foreach (ProtectedBrand::query()->pluck('domain') as $domain) {
            foreach (explode('.', strtolower($domain)) as $label) {
                $known[$label] = true;
            }
        }

        return $known;
    }

    private function host(string $line): ?string
    {
        $line = trim((string) preg_replace('/^\S+\s+(?=\w+:\/\/)/', '', trim($line)));

        if (str_contains($line, '://')) {
            $host = parse_url($line, PHP_URL_HOST);

            return is_string($host) && $host !== '' ? strtolower($host) : null;
        }

        $host = preg_split('/[\s\/?#]/', $line)[0] ?? '';
        $host = explode(':', $host)[0] ?? '';

        return $host === '' ? null : strtolower(rtrim($host, '.'));
    }
}
