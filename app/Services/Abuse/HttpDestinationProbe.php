<?php

declare(strict_types=1);

namespace App\Services\Abuse;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
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
        $result = null;

        while ($result === null) {
            $step = $this->step($current, $redirects);

            if (is_string($step)) {
                $current = $step;
                $redirects++;

                continue;
            }

            $result = $step;
        }

        return $result;
    }

    /**
     * One hop: either a final result, or the next URL to follow.
     */
    private function step(string $url, int $redirects): ProbeResult|string
    {
        $target = $this->target($url);

        if ($target === null || ($target['private'] ?? false) === true) {
            return new ProbeResult(0, $redirects, $target !== null);
        }

        $response = $this->send($url, $target);

        return $response === null
            ? new ProbeResult(0, $redirects)
            : $this->outcome($url, $response, $redirects, $target['address']);
    }

    /**
     * @param  array{host: string, port: int, address: string}  $target
     */
    private function send(string $url, array $target): ?Response
    {
        try {
            $response = $this->request($target['host'], $target['port'], $target['address'])
                ->head($url);

            if (in_array($response->status(), [400, 403, 405, 501], true)) {
                $response = $this->request($target['host'], $target['port'], $target['address'])
                    ->withHeaders(['Range' => 'bytes=0-'.((int) config('abuse.http.range_bytes', 65_536) - 1)])
                    ->get($url);
            }

            return $response;
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }
    }

    private function outcome(string $url, Response $response, int $redirects, string $address): ProbeResult|string
    {
        $location = $response->header('Location');

        if (! $response->redirect() || ! is_string($location) || $location === '') {
            return new ProbeResult($response->status(), $redirects, false, $address);
        }

        if ($redirects + 1 >= (int) config('abuse.http.max_redirects', 3)) {
            return new ProbeResult($response->status(), $redirects + 1, false, $address);
        }

        return $this->resolve($url, $location);
    }

    /**
     * @return array{host: string, port: int, address: string}|array{private: true}|null
     */
    private function target(string $url): ?array
    {
        $parts = parse_url($url);
        $parts = is_array($parts) ? $parts : [];

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));

        $addresses = in_array($scheme, ['http', 'https'], true) && $host !== ''
            ? $this->addresses($host)
            : [];

        if ($addresses === []) {
            return null;
        }

        if ($this->private->anyPrivate($addresses)) {
            return ['private' => true];
        }

        return [
            'host' => $this->asciiHost($host),
            'port' => $this->port($parts, $scheme),
            'address' => $addresses[0],
        ];
    }

    /**
     * @return list<string>
     */
    private function addresses(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        return $this->resolver->addresses($this->asciiHost($host));
    }

    /**
     * CURLOPT_RESOLVE and DNS answers are indexed by the ASCII hostname.
     */
    private function asciiHost(string $host): string
    {
        $ascii = idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);

        return is_string($ascii) && $ascii !== '' ? $ascii : $host;
    }

    /**
     * @param  array<string, mixed>  $parts
     */
    private function port(array $parts, string $scheme): int
    {
        if (is_int($parts['port'] ?? null)) {
            return $parts['port'];
        }

        return $scheme === 'https' ? 443 : 80;
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
        $parts = is_array($parts) ? $parts : [];

        $scheme = strtolower((string) ($parts['scheme'] ?? 'http'));
        $origin = $scheme.'://'.($parts['host'] ?? '').(isset($parts['port']) ? ':'.$parts['port'] : '');

        return match (true) {
            str_starts_with($location, '//') => $scheme.':'.$location,
            str_starts_with($location, '/') => $origin.$location,
            default => $origin.$this->directory((string) ($parts['path'] ?? '/')).$location,
        };
    }

    private function directory(string $path): string
    {
        if (str_ends_with($path, '/')) {
            return $path;
        }

        $position = strrpos($path, '/');
        $directory = substr($path, 0, $position === false ? 1 : $position + 1);

        return $directory === '' ? '/' : $directory;
    }
}
