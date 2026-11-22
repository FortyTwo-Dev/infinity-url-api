<?php

declare(strict_types=1);

namespace App\Services\Abuse;

use Pdp\Domain;
use Pdp\Rules;
use Throwable;

/**
 * Registrable-domain extraction, backed by the Public Suffix List.
 *
 * Without the PSL, `a.b.example.co.uk` and `example.co.uk` would be treated as
 * two different domains and a subdomain would bypass the blocklist. The list is
 * local (resources/psl, overridable with PSL_PATH): no network call ever runs
 * on the creation path.
 */
final class PublicSuffix
{
    private ?Rules $rules = null;

    private bool $loaded = false;

    public function path(): string
    {
        return (string) config('abuse.psl_path', resource_path('psl/public_suffix_list.dat'));
    }

    /**
     * The registrable domain, lowercased, or null when the host is not a
     * valid domain name.
     */
    public function registrable(string $host): ?string
    {
        $host = rtrim(strtolower(trim($host)), '.');

        if ($host === '' || str_contains($host, ' ')) {
            return null;
        }

        $rules = $this->rules();

        if ($rules !== null) {
            try {
                $domain = $rules->resolve(Domain::fromIDNA2008($host));
                $registrable = $domain->registrableDomain()->toString();

                if ($registrable !== '') {
                    return $registrable;
                }
            } catch (Throwable) {
                // Fall through to the conservative fallback.
            }
        }

        return $this->fallback($host);
    }

    /**
     * The labels of a host, left to right.
     *
     * @return list<string>
     */
    public function labels(string $host): array
    {
        $host = rtrim(strtolower(trim($host)), '.');

        return $host === '' ? [] : explode('.', $host);
    }

    private function rules(): ?Rules
    {
        if ($this->loaded) {
            return $this->rules;
        }

        $this->loaded = true;
        $path = $this->path();

        if (! is_file($path)) {
            report(new \RuntimeException('Public suffix list missing at '.$path));

            return $this->rules = null;
        }

        try {
            return $this->rules = Rules::fromPath($path);
        } catch (Throwable $exception) {
            report($exception);

            return $this->rules = null;
        }
    }

    private function fallback(string $host): ?string
    {
        $labels = $this->labels($host);

        return count($labels) < 2 ? null : implode('.', array_slice($labels, -2));
    }
}
