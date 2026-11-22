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
        $parts = is_array($parts) ? $parts : [];

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower(trim((string) ($parts['host'] ?? ''), '[]'));

        if ($host === '' || ! in_array($scheme, ['http', 'https'], true)) {
            return DestinationAssessment::accept();
        }

        return $this->assess($destination, $scheme, $host, (string) ($parts['path'] ?? ''));
    }

    private function assess(string $destination, string $scheme, string $host, string $path): DestinationAssessment
    {
        $registrable = $this->suffix->registrable($host);
        $brand = $this->brands->match($host);
        /** @var array<string, mixed> $signals */
        $signals = [
            'registrable' => $registrable,
            'domain_age_days' => null,
            'tls_age_days' => null,
        ];

        // Blocking signals are local and cheap: they must stop before RDAP.
        return $this->blocking($host, $brand, $signals)
            ?? $this->brandAndAge($host, $registrable, $brand, $path, $signals)
            ?? $this->network($destination, $scheme, $host, $signals);
    }

    /**
     * @param  array<string, mixed>  $signals
     */
    private function blocking(string $host, ?BrandMatch $brand, array $signals): ?DestinationAssessment
    {
        if ($this->blocklist->hits($host)) {
            return DestinationAssessment::block(
                'blocklist',
                'its domain is on a public abuse blocklist.',
                $signals,
            );
        }

        if ($brand?->isBlocking() === true) {
            return DestinationAssessment::block(
                $brand->reason(),
                $this->describe($brand),
                $signals,
            );
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $signals
     */
    private function brandAndAge(
        string $host,
        ?string $registrable,
        ?BrandMatch $brand,
        string $path,
        array $signals,
    ): ?DestinationAssessment {
        $sensitive = $this->hasSensitivePath($path);

        if ($brand === null && ! $sensitive) {
            return $this->heuristics($host, $registrable, $signals);
        }

        $age = $this->rdap->ageDays($registrable);
        $signals['domain_age_days'] = $age;

        return $this->brandVerdict($brand, $age, $signals)
            ?? $this->youngVerdict($sensitive, $age, $signals)
            ?? $this->heuristics($host, $registrable, $signals);
    }

    /**
     * @param  array<string, mixed>  $signals
     */
    private function brandVerdict(?BrandMatch $brand, ?int $age, array $signals): ?DestinationAssessment
    {
        if ($brand?->kind !== BrandMatch::TYPOSQUAT) {
            return null;
        }

        if ($this->isYoung($age)) {
            return DestinationAssessment::block(
                $brand->reason(),
                $this->describe($brand).' The domain was registered '.$age.' day(s) ago.',
                $signals,
            );
        }

        return DestinationAssessment::review($brand->reason(), $signals);
    }

    /**
     * @param  array<string, mixed>  $signals
     */
    private function youngVerdict(bool $sensitive, ?int $age, array $signals): ?DestinationAssessment
    {
        return $sensitive && $this->isYoung($age)
            ? DestinationAssessment::review('young_domain_login_path', $signals)
            : null;
    }

    /**
     * @param  array<string, mixed>  $signals
     */
    private function heuristics(string $host, ?string $registrable, array $signals): ?DestinationAssessment
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return $this->private->isPrivate($host)
                ? DestinationAssessment::block('private_address', 'it points to a private or reserved address.', $signals)
                : DestinationAssessment::review('ip_literal', $signals);
        }

        $extraLabels = count($this->suffix->labels($host))
            - count($this->suffix->labels((string) $registrable));

        return $extraLabels > (int) config('abuse.max_subdomains', 3)
            ? DestinationAssessment::review('many_subdomains:'.$extraLabels, $signals)
            : null;
    }

    /**
     * @param  array<string, mixed>  $signals
     */
    private function network(string $destination, string $scheme, string $host, array $signals): DestinationAssessment
    {
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

        return $this->probed($destination, $scheme, $host, $signals);
    }

    /**
     * @param  array<string, mixed>  $signals
     */
    private function probed(string $destination, string $scheme, string $host, array $signals): DestinationAssessment
    {
        $probe = $this->probe->probe($destination);

        if ($probe->privateHop || ! $probe->reachable()) {
            return $probe->privateHop
                ? DestinationAssessment::block(
                    'private_address',
                    'a redirection points to a private, loopback or metadata address.',
                    $signals,
                )
                : DestinationAssessment::accept($signals + ['http' => 'unreachable']);
        }

        return $probe->redirects >= (int) config('abuse.http.max_redirects', 3)
            ? DestinationAssessment::review('redirect_chain:'.$probe->redirects, $signals)
            : $this->accepted($scheme, $host, $probe->address, $signals);
    }

    /**
     * @param  array<string, mixed>  $signals
     */
    private function accepted(string $scheme, string $host, ?string $address, array $signals): DestinationAssessment
    {
        if ($scheme === 'https' && $address !== null) {
            $issued = $this->tls->issuedAt($host, $address);

            if ($issued !== null) {
                $signals['tls_age_days'] = max(0, (int) $issued->diffInDays(now('UTC')));
            }
        }

        return DestinationAssessment::accept($signals);
    }

    private function isYoung(?int $age): bool
    {
        return $age !== null && $age < (int) config('abuse.young_domain_days', 30);
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
