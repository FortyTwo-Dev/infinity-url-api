<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Crypt;
use Throwable;

final class SignedPayload
{
    public function issue(string $purpose, string $subject, int $ttlSeconds): string
    {
        $payload = [
            'purpose' => $purpose,
            'sub' => $subject,
            'exp' => now()->addSeconds($ttlSeconds)->getTimestamp(),
        ];

        return Crypt::encryptString((string) json_encode($payload));
    }

    public function resolve(string $token, string $purpose): ?string
    {
        $decoded = base64_decode($token, true);

        if ($decoded === false || base64_encode($decoded) !== $token) {
            return null;
        }

        try {
            $payload = json_decode(Crypt::decryptString($token), true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }

        if (! is_array($payload)
            || ($payload['purpose'] ?? null) !== $purpose
            || ! is_string($payload['sub'] ?? null)
            || ! is_int($payload['exp'] ?? null)
            || $payload['exp'] < now()->getTimestamp()) {
            return null;
        }

        return $payload['sub'];
    }
}
