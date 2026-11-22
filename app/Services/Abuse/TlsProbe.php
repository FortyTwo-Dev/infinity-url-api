<?php

declare(strict_types=1);

namespace App\Services\Abuse;

use Carbon\CarbonImmutable;

/**
 * The certificate's `notBefore` is a signal, never a decision: a fresh
 * certificate on a fresh domain reinforces a suspicion, it never creates one.
 */
interface TlsProbe
{
    public function issuedAt(string $host, string $address): ?CarbonImmutable;
}
