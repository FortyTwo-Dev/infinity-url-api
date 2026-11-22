<?php

declare(strict_types=1);

namespace App\Services\Abuse;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * HEAD, then a partial GET when a server refuses HEAD. Every hop is resolved
 * again and its address checked: a redirect is a fresh destination, and a DNS
 * rebinding between two hops is exactly what a single check would miss.
 *
 * The connection is pinned to the checked address with CURLOPT_RESOLVE, so the
 * address that was verified is the address that is contacted.
 */
final class HttpDestinationProbe
{
    public function __construct(
        private readonly DnsResolver $resolver,
        private readonly PrivateAddress $private,
    ) {}

    public function probe(string $url): ProbeResult
    {
        $redirects = 0;
        $current = $url;
        $maximum = (int) config('abuse.http.max_redirects', 3);

        while (true) {
            $target = $this->target($current);

            if ($target === null) {
                return new ProbeResult(0, $redirects);
            }

            if (($target['private'] ?? false) === true) {
                return new ProbeResult(0, $redirects, true);
            }

            try {
                $response = $this->request($target['host'], $target['port'], $target['address'])
                    ->head($current);

                if (in_array($response->status(), [400, 403, 405, 501], true)) {
                    $response = $this->request($target['host'], $target['port'], $target['address'])
                        ->withHeaders(['Range' => 'bytes=0-'.((int) config('abuse.http.range_bytes', 65_536) - 1)])
                        ->get($current);
                }
            } catch (Throwable $exception) {
                report($exception);

                return new ProbeResult(0, $redirects);
            }

            $location = $response->header('Location');

            if ($response->redirect() && is_string($location) && $location !== '') {
                $redirects++;

                if ($redirects >= $maximum) {
                    return new ProbeResult($response->status(), $redirects, false, $target['address']);
                }

                $current = $this->resolve($current, $location);

                continue;
            }

            return new ProbeResult($response->status(), $redirects, false, $target['address']);
        }
    }

    /**
     * @return array{host: string, port: int, address: string}|array{private: true}|null
     */
    private function target(string $url): ?array
    {
        $parts = parse_url($url);

        if (! is_array($parts)) {
            return null;
        }

        $scheme = strtolower($parts['scheme'] ?? '');
        $host = strtolower($parts['host'] ?? '');

        if (! in_array($scheme, ['http', 'https'], true) || $host === '') {
            return null;
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return $this->private->isPrivate($host)
                ? ['private' => true]
                : ['host' => $host, 'port' => $this->port($parts, $scheme), 'address' => $host];
        }

        $addresses = $this->resolver->addresses($host);

        if ($addresses === []) {
            return null;
        }

        if ($this->private->anyPrivate($addresses)) {
            return ['private' => true];
        }

        return [
            'host' => $host,
            'port' => $this->port($parts, $scheme),
            'address' => $addresses[0],
        ];
    }

    /**
     * @param  array<string, mixed>  $parts
     */
    private function port(array $parts, string $scheme): int
    {
        return is_int($parts['port'] ?? null)
            ? $parts['port']
            : ($scheme === 'https' ? 443 : 80);
    }

    private function request(string $host, int $port, string $address): PendingRequest
    {
        $pinned = str_contains($address, ':') ? '['.$address.']' : $address;

        return Http::withOptions([
            'curl' => [
                CURLOPT_RESOLVE => [$host.':'.$port.':'.$pinned],
            ],
        ])
            ->withHeaders([
                'User-Agent' => (string) config('abuse.http.user_agent', 'InfinityURL-AbuseCheck/1.0'),
                'Accept' => 'text/html,application/xhtml+xml',
            ])
            ->timeout((int) config('abuse.timeouts.http', 3))
            ->connectTimeout((int) config('abuse.timeouts.http', 3))
            ->withoutRedirecting();
    }

    private function resolve(string $base, string $location): string
    {
        if (preg_match('#^https?://#i', $location) === 1) {
            return $location;
        }

        $parts = parse_url($base);

        if (! is_array($parts)) {
            return $location;
        }

        $scheme = strtolower($parts['scheme'] ?? 'http');
        $origin = $scheme.'://'.($parts['host'] ?? '').(isset($parts['port']) ? ':'.$parts['port'] : '');

        if (str_starts_with($location, '//')) {
            return $scheme.':'.$location;
        }

        if (str_starts_with($location, '/')) {
            return $origin.$location;
        }

        $path = $parts['path'] ?? '/';
        $position = strrpos($path, '/');
        $directory = str_ends_with($path, '/')
            ? $path
            : substr($path, 0, $position === false ? 1 : $position + 1);

        return $origin.($directory === '' ? '/' : $directory).$location;
    }
}
