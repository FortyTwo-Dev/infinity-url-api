<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;

final class EmailHasher
{
    public static function normalize(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    public static function hash(string $email): string
    {
        $key = config('identity.blind_index_key');

        if (! is_string($key) || $key === '') {
            throw new RuntimeException('BLIND_INDEX_KEY is not configured.');
        }

        return hash_hmac('sha256', self::normalize($email), $key);
    }
}
