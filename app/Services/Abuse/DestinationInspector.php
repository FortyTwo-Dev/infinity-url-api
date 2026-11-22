<?php

declare(strict_types=1);

namespace App\Services\Abuse;

use App\Enums\LookalikeVariant;

/**
 * The deterministic, three-outcome check of docs/04 §3.5 and D-128.
 *
 * Local signals first, so an obvious block costs no network call. Then DNS and
 * address filtering, then RDAP only when it changes the outcome, then the HTTP
 * probe and the certificate date. Every network failure fails open: the
 * absence of proof of safety is not a proof of danger (D-126).
 */
final class DestinationInspector
{
    public function __construct(
        private readonly Blocklist $blocklist,
        private readonly BrandMatcher $brands,
        private readonly PublicSuffix $suffix,
        private readonly RdapClient $rdap,
        private readonly DnsResolver $resolver,
        private readonly PrivateAddress $private,
        private readonly HttpDestinationProbe $probe,
        private readonly TlsProbe $tls,
    ) {}

    public function inspect(string $destination): DestinationAssessment
    {
        if (! (bool) config('abuse.enabled', true)) {
            return DestinationAssessment::accept(['enabled' => false]);
        }

        $parts = parse_url($destination);

        if (! is_array($parts)) {
            return DestinationAssessment::accept();
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower(trim((string) ($parts['host'] ?? ''), '[]'));

        if ($host === '' || ! in_array($scheme, ['http', 'https'], true)) {
            return DestinationAssessment::accept();
        }

        $registrable = $this->suffix->registrable($host);
        /** @var array<string, mixed> $signals */
        $signals = [
            'registrable' => $registrable,
            'domain_age_days' => null,
            'tls_age_days' => null,
        ];

        if ($this->blocklist->hits($host)) {
            return DestinationAssessment::block(
                'blocklist',
                'its domain is on a public abuse blocklist.',
                $signals,
            );
        }

        $brand = $this->brands->match($host);

        if ($brand?->isBlocking() === true) {
            return DestinationAssessment::block(
                $brand->reason(),
                $this->describe($brand),
                $signals,
            );
        }

        $sensitive = $this->hasSensitivePath((string) ($parts['path'] ?? ''));

        if ($brand !== null || $sensitive) {
            $signals['domain_age_days'] = $this->rdap->ageDays($registrable);
        }

        $young = $this->isYoung($signals['domain_age_days']);

        if ($brand?->kind === BrandMatch::TYPOSQUAT) {
            if ($young) {
                return DestinationAssessment::block(
                    $brand->reason(),
                    $this->describe($brand).' The domain was registered '
                        .$signals['domain_age_days'].' day(s) ago.',
                    $signals,
                );
            }

            return DestinationAssessment::review($brand->reason(), $signals);
        }

        if ($sensitive && $young) {
            return DestinationAssessment::review('young_domain_login_path', $signals);
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return $this->private->isPrivate($host)
                ? DestinationAssessment::block('private_address', 'it points to a private or reserved address.', $signals)
                : DestinationAssessment::review('ip_literal', $signals);
        }

        $labels = $this->suffix->labels($host);
        $extraLabels = count($labels) - count($this->suffix->labels((string) $registrable));

        if ($extraLabels > (int) config('abuse.max_subdomains', 3)) {
            return DestinationAssessment::review('many_subdomains:'.$extraLabels, $signals);
        }

        $addresses = $this->resolver->addresses($host);

        if ($addresses === []) {
            $signals['dns'] = 'unresolved';

            return DestinationAssessment::accept($signals);
        }

        if ($this->private->anyPrivate($addresses)) {
            return DestinationAssessment::block(
                'private_address',
                'it resolves to a private, loopback or metadata address.',
                $signals,
            );
        }

        $probe = $this->probe->probe($destination);

        if ($probe->privateHop) {
            return DestinationAssessment::block(
                'private_address',
                'a redirection points to a private, loopback or metadata address.',
                $signals,
            );
        }

        if (! $probe->reachable()) {
            $signals['http'] = 'unreachable';

            return DestinationAssessment::accept($signals);
        }

        if ($probe->redirects >= (int) config('abuse.http.max_redirects', 3)) {
            return DestinationAssessment::review('redirect_chain:'.$probe->redirects, $signals);
        }

        if ($scheme === 'https' && $probe->address !== null) {
            $issued = $this->tls->issuedAt($host, $probe->address);

            if ($issued !== null) {
                $signals['tls_age_days'] = max(0, (int) $issued->diffInDays(now('UTC')));
            }
        }

        return DestinationAssessment::accept($signals);
    }

    private function isYoung(mixed $age): bool
    {
        return is_int($age) && $age < (int) config('abuse.young_domain_days', 30);
    }

    private function hasSensitivePath(string $path): bool
    {
        $keywords = (array) config('abuse.sensitive_paths', []);

        if ($keywords === []) {
            return false;
        }

        $pattern = '/(^|[^a-z])('.implode('|', array_map(
            static fn (mixed $keyword): string => preg_quote((string) $keyword, '/'),
            $keywords,
        )).')([^a-z]|$)/i';

        return preg_match($pattern, $path) === 1;
    }

    private function describe(BrandMatch $match): string
    {
        return match ($match->kind) {
            BrandMatch::TYPOSQUAT => 'it imitates the protected domain '
                .($match->brand ?? '')
                .($match->variant instanceof LookalikeVariant ? ' ('.$match->variant->value.')' : '')
                .'.',
            BrandMatch::SUBDOMAIN => 'it hides the protected domain '.($match->brand ?? '').' in a subdomain.',
            default => 'it mixes Unicode scripts, a homograph technique.',
        };
    }
}
