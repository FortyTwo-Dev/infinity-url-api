<?php

declare(strict_types=1);

namespace App\Services\Abuse;

final class SystemDnsResolver implements DnsResolver
{
    public function addresses(string $host): array
    {
        $host = rtrim(strtolower(trim($host)), '.');

        if ($host === '') {
            return [];
        }

        // DNS answers are indexed by the ASCII form: a legitimate IDN still
        // has to resolve and be checked like any other destination.
        $ascii = idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);

        if (is_string($ascii) && $ascii !== '') {
            $host = $ascii;
        }

        $addresses = [];

        /** @var list<string>|false $v4 */
        $v4 = @gethostbynamel($host);

        if (is_array($v4)) {
            $addresses = $v4;
        }

        /** @var list<array{ipv6?: string}>|false $records */
        $records = @dns_get_record($host, DNS_AAAA);

        if (is_array($records)) {
            foreach ($records as $record) {
                if (isset($record['ipv6']) && is_string($record['ipv6'])) {
                    $addresses[] = $record['ipv6'];
                }
            }
        }

        return array_values(array_unique($addresses));
    }
}
