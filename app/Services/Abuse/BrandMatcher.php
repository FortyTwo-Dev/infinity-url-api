<?php

declare(strict_types=1);

namespace App\Services\Abuse;

use App\Enums\ProtectedBrandType;
use App\Models\LookalikeDomain;
use App\Models\ProtectedBrand;

/**
 * One indexed read for the typosquat table, plus two calculations that need no
 * table at all: a brand buried in a third-party subdomain, and mixed scripts.
 */
final class BrandMatcher
{
    /** @var list<ProtectedBrand>|null */
    private ?array $brands = null;

    public function __construct(
        private readonly PublicSuffix $suffix,
        private readonly Scripts $scripts,
    ) {}

    public function match(string $host): ?BrandMatch
    {
        $ascii = $this->scripts->ascii($host);

        if ($ascii === null) {
            return null;
        }

        $registrable = $this->suffix->registrable($ascii);

        if ($registrable === null) {
            return null;
        }

        // The table gives the most citable reason: a known homoglyph of a
        // protected brand is quoted by name, not as "mixed scripts".
        $lookalike = LookalikeDomain::query()->find($registrable);

        if ($lookalike !== null) {
            return BrandMatch::typosquat($lookalike->brand_domain, $lookalike->variant_type);
        }

        if ($this->scripts->isMixed($ascii)) {
            return BrandMatch::mixedScripts();
        }

        return $this->subdomain($ascii, $registrable);
    }

    private function subdomain(string $host, string $registrable): ?BrandMatch
    {
        $labels = $this->suffix->labels($host);
        $registrableLabels = count($this->suffix->labels($registrable));

        if ($registrableLabels === 0 || count($labels) <= $registrableLabels) {
            return null;
        }

        $before = array_slice($labels, 0, count($labels) - $registrableLabels);

        foreach ($this->brands() as $brand) {
            $brandDomain = strtolower($brand->domain);

            if ($brand->type === ProtectedBrandType::Hostname) {
                if ($this->hostnameIsBuried($labels, $brandDomain)) {
                    return BrandMatch::subdomain($brandDomain);
                }

                continue;
            }

            if ($brandDomain === $registrable) {
                continue;
            }

            $brandLabel = explode('.', $brandDomain)[0] ?? '';

            if ($brandLabel !== '' && in_array($brandLabel, $before, true)) {
                return BrandMatch::subdomain($brandDomain);
            }
        }

        return null;
    }

    /**
     * `impots.gouv.fr` inside `impots.gouv.fr.connexion.example.com`: the
     * brand labels are a strict prefix of the host.
     *
     * @param  list<string>  $labels
     */
    private function hostnameIsBuried(array $labels, string $brandDomain): bool
    {
        $brandLabels = explode('.', $brandDomain);

        if (count($labels) <= count($brandLabels)) {
            return false;
        }

        return array_slice($labels, 0, count($brandLabels)) === $brandLabels;
    }

    /**
     * @return list<ProtectedBrand>
     */
    private function brands(): array
    {
        return $this->brands ??= ProtectedBrand::query()->get()->all();
    }
}
