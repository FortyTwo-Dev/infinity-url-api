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
        $line = trim((string) preg_replace('/^\S+\s+(?=\w+:\/\/)/', '', $line));

        return str_contains($line, '://')
            ? $this->urlHost($line)
            : $this->bareHost($line);
    }

    private function urlHost(string $url): ?string
    {
        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) && $host !== '' ? strtolower($host) : null;
    }

    private function bareHost(string $line): ?string
    {
        $host = preg_split('/[\s\/?#]/', $line)[0] ?? '';
        $host = explode(':', $host)[0] ?? '';

        return $host === '' || str_contains($host, '@') || filter_var($host, FILTER_VALIDATE_IP) !== false
            ? null
            : strtolower(rtrim($host, '.'));
    }
}
