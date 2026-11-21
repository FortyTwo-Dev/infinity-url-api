<?php

declare(strict_types=1);

namespace App\Casts;

use App\Services\EmailHasher;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;
use Throwable;

/**
 * Encrypts an email at rest and keeps its blind index in sync.
 *
 * The compare() behaviour is intentional: encryption is non-deterministic, so
 * re-saving the same plaintext must not rewrite the ciphertext, the blind index
 * or `updated_at` (D-110).
 *
 * @implements CastsAttributes<string|null, string|null>
 */
final class EncryptedWithHash implements CastsAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        return EmailHasher::normalize(Crypt::decryptString((string) $value));
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, string|null>
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): array
    {
        $hashKey = $key.'_hash';

        if ($value === null) {
            return [$key => null, $hashKey => null];
        }

        $normalized = EmailHasher::normalize((string) $value);
        $current = $attributes[$key] ?? null;

        if (is_string($current)) {
            try {
                if (EmailHasher::normalize(Crypt::decryptString($current)) === $normalized) {
                    return [];
                }
            } catch (Throwable) {
                // A value we cannot decrypt is rewritten rather than trusted.
            }
        }

        return [
            $key => Crypt::encryptString($normalized),
            $hashKey => EmailHasher::hash($normalized),
        ];
    }

    /**
     * Keep Laravel's dirty check stable despite the random IV: compare the
     * decrypted values, not the ciphertexts. This is the `compare()` hook of
     * the Hytale cast (D-110).
     */
    public function compare(Model $model, string $key, mixed $original, mixed $value): bool
    {
        if ($original === null || $value === null) {
            return $original === $value;
        }

        try {
            return EmailHasher::normalize(Crypt::decryptString((string) $original))
                === EmailHasher::normalize(Crypt::decryptString((string) $value));
        } catch (Throwable) {
            return false;
        }
    }
}
