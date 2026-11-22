<?php

declare(strict_types=1);

namespace App\Services\Abuse;

/**
 * Turns one dump line into a registrable domain. Dumps mix full URLs, bare
 * hostnames, comments and blank lines.
 */
final class DumpParser
{
    public function __construct(private readonly PublicSuffix $suffix) {}

    public function registrable(string $line): ?string
    {
        $line = trim($line);

        if ($line === '' || str_starts_with($line, '#') || str_starts_with($line, '//')) {
            return null;
        }

        $host = $this->host($line);

        return $host === null ? null : $this->suffix->registrable($host);
    }

    /**
     * @param  list<string>  $lines
     * @return array<string, true>
     */
    public function domains(array $lines): array
    {
        $domains = [];

        foreach ($lines as $line) {
            $domain = $this->registrable($line);

            if ($domain !== null) {
                $domains[$domain] = true;
            }
        }

        return $domains;
    }

    private function host(string $line): ?string
    {
        // Some feeds prefix a URL with a comment tag: "123456 http://…".
        $line = preg_replace('/^\S+\s+(?=\w+:\/\/)/', '', $line) ?? $line;

        if (str_contains($line, '://')) {
            $host = parse_url($line, PHP_URL_HOST);

            return is_string($host) && $host !== '' ? strtolower($host) : null;
        }

        $host = preg_split('/[\s\/?#]/', $line)[0] ?? '';

        if ($host === '' || str_contains($host, '@')) {
            return null;
        }

        $host = explode(':', $host)[0] ?? '';

        if ($host === '' || filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return null;
        }

        return strtolower(rtrim($host, '.'));
    }
}
