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
        $value = trim($value);

        if ($value === '') {
            return null;
        }

        $host = $defaultHost;
        $slug = $value;

        if (str_starts_with($value, '//')) {
            $value = 'https:'.$value;
        }

        if (str_contains($value, '://')) {
            $parts = parse_url($value);

            if (! is_array($parts) || ! isset($parts['host'], $parts['path'])) {
                return null;
            }

            $host = (string) $parts['host'];
            $slug = trim((string) $parts['path'], '/');

            if (str_contains($slug, '/')) {
                return null;
            }
        } elseif (str_contains($value, '/')) {
            [$candidateHost, $candidateSlug] = explode('/', $value, 2);

            if ($candidateHost === '' || str_contains($candidateSlug, '/')) {
                return null;
            }

            $host = $candidateHost;
            $slug = $candidateSlug;
        }

        $host = strtolower(rtrim(trim($host), '.'));
        $slug = trim($slug);

        if (! self::validHost($host) || preg_match(self::SLUG, $slug) !== 1) {
            return null;
        }

        return new self($host, strtolower($slug));
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
