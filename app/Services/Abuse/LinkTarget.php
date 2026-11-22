<?php

declare(strict_types=1);

namespace App\Services\Abuse;

/**
 * What the reporter typed, normalised into a host and a slug.
 *
 * The form asks for a short link, but people paste the URL they received:
 * both `abc123`, `iurl.fr/abc123` and `https://iurl.fr/abc123` must work. An
 * unparseable value is the only malformed input that answers 422.
 */
final class LinkTarget
{
    private const SLUG = '/^[A-Za-z0-9_-]{2,64}$/';

    private function __construct(
        public readonly string $host,
        public readonly string $slug,
    ) {}

    public static function parse(string $value, string $defaultHost): ?self
    {
        [$host, $slug] = self::split(trim($value), $defaultHost);

        $host = strtolower(rtrim(trim($host), '.'));
        $slug = trim($slug);

        return self::validHost($host) && preg_match(self::SLUG, $slug) === 1
            ? new self($host, strtolower($slug))
            : null;
    }

    /**
     * @return array{0: string, 1: string}
     */
    private static function split(string $value, string $defaultHost): array
    {
        $value = str_starts_with($value, '//') ? 'https:'.$value : $value;

        if (str_contains($value, '://')) {
            $parts = parse_url($value);
            $parts = is_array($parts) ? $parts : [];
            $slug = trim((string) ($parts['path'] ?? ''), '/');

            return [
                (string) ($parts['host'] ?? ''),
                str_contains($slug, '/') ? '' : $slug,
            ];
        }

        if (str_contains($value, '/')) {
            [$host, $slug] = explode('/', $value, 2);

            return [$host, str_contains($slug, '/') ? '' : $slug];
        }

        return [$defaultHost, $value];
    }

    private static function validHost(string $host): bool
    {
        if ($host === '' || strlen($host) > 253) {
            return false;
        }

        return preg_match('/^[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?$/', $host) === 1
            && str_contains($host, '.');
    }
}
