<?php

declare(strict_types=1);

namespace App\Services\Abuse;

/**
 * Private, loopback, link-local and metadata addresses are never a destination
 * (docs/04 §3.1). The ranges are written out instead of delegated to
 * `filter_var`, so the list can be read, tested and extended.
 */
final class PrivateAddress
{
    // NOSONAR on each line below: these are documented reserved ranges to
    // reject, not endpoints to contact.
    private const RANGES = [
        '0.0.0.0/8', // NOSONAR
        '10.0.0.0/8', // NOSONAR
        '100.64.0.0/10', // NOSONAR
        '127.0.0.0/8', // NOSONAR
        '169.254.0.0/16', // NOSONAR
        '172.16.0.0/12', // NOSONAR
        '192.168.0.0/16', // NOSONAR
        '224.0.0.0/4', // NOSONAR
        '240.0.0.0/4', // NOSONAR
        '::/128', // NOSONAR
        '::1/128', // NOSONAR
        'fc00::/7', // NOSONAR
        'fe80::/10', // NOSONAR
    ];

    public function isPrivate(string $address): bool
    {
        $address = $this->normalize($address);

        if ($address === null) {
            return true;
        }

        foreach (self::RANGES as $range) {
            if ($this->inRange($address, $range)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $addresses
     */
    public function anyPrivate(array $addresses): bool
    {
        foreach ($addresses as $address) {
            if ($this->isPrivate($address)) {
                return true;
            }
        }

        return false;
    }

    private function normalize(string $address): ?string
    {
        $address = trim($address);

        if (filter_var($address, FILTER_VALIDATE_IP) === false) {
            return null;
        }

        // An IPv4-mapped IPv6 address is an IPv4 address in disguise.
        if (str_starts_with($address, '::ffff:') && filter_var(substr($address, 7), FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return substr($address, 7);
        }

        return $address;
    }

    private function inRange(string $address, string $range): bool
    {
        [$network, $bits] = explode('/', $range);
        $addressBytes = inet_pton($address);
        $networkBytes = inet_pton($network);

        if ($addressBytes === false || $networkBytes === false || strlen($addressBytes) !== strlen($networkBytes)) {
            return false;
        }

        $bits = (int) $bits;
        $whole = intdiv($bits, 8);
        $rest = $bits % 8;

        if (strncmp($addressBytes, $networkBytes, $whole) !== 0) {
            return false;
        }

        if ($rest === 0) {
            return true;
        }

        $mask = 0xFF << (8 - $rest) & 0xFF;

        return (ord($addressBytes[$whole]) & $mask) === (ord($networkBytes[$whole]) & $mask);
    }
}
