<?php

declare(strict_types=1);

namespace App\Services\Analytics;

/**
 * The two fingerprints of one click.
 *
 * Both integrate the internal link id, never the slug and never the
 * destination: the same IP on two links produces two unrelated keys, so
 * cross-link correlation is impossible (D-02, docs/06 §B.4).
 */
final class DedupKey
{
    public function weekly(string $ip, int $linkId, string $salt): string
    {
        return hash_hmac('sha256', $ip.'|'.$linkId, $salt);
    }

    public function daily(string $ip, int $linkId, string $date, string $salt): string
    {
        return hash_hmac('sha256', $ip.'|'.$linkId.'|'.$date, $salt);
    }
}
