<?php

declare(strict_types=1);

namespace App\Services\Abuse;

use Carbon\CarbonImmutable;
use Throwable;

/**
 * Reads the peer certificate on the pinned address, with SNI set to the real
 * hostname. Verification is off on purpose: the certificate is not what we
 * judge, only the dates.
 */
final class StreamTlsProbe implements TlsProbe
{
    public function issuedAt(string $host, string $address): ?CarbonImmutable
    {
        $timeout = (float) config('abuse.timeouts.tls', 3);
        $target = str_contains($address, ':') ? '['.$address.']' : $address;
        $context = stream_context_create([
            'ssl' => [
                'peer_name' => $host,
                'SNI_enabled' => true,
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true,
            ],
        ]);

        $socket = @stream_socket_client(
            'tls://'.$target.':443',
            $errorCode,
            $errorMessage,
            $timeout,
            STREAM_CLIENT_CONNECT,
            $context,
        );

        if ($socket === false) {
            return null;
        }

        try {
            $parameters = stream_context_get_params($socket);
            $certificate = $parameters['options']['ssl']['peer_certificate'] ?? null;

            if (! is_resource($certificate) && ! is_object($certificate)) {
                return null;
            }

            $parsed = openssl_x509_parse($certificate);

            if (! is_array($parsed) || ! isset($parsed['validFrom_time_t'])) {
                return null;
            }

            return CarbonImmutable::createFromTimestampUTC((int) $parsed['validFrom_time_t']);
        } catch (Throwable $exception) {
            report($exception);

            return null;
        } finally {
            fclose($socket);
        }
    }
}
