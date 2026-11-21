<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Validation\ValidationException;

final class Cursor
{
    /**
     * @param  array<string, mixed>  $values
     */
    public static function encode(array $values): string
    {
        return rtrim(strtr(base64_encode((string) json_encode($values)), '+/', '-_'), '=');
    }

    /**
     * @return array<string, mixed>
     */
    public static function decode(string $cursor): array
    {
        $padded = str_pad(strtr($cursor, '-_', '+/'), (int) (ceil(strlen($cursor) / 4) * 4), '=');
        $decoded = base64_decode($padded, true);

        if ($decoded === false) {
            throw ValidationException::withMessages([
                'cursor' => ['The cursor is invalid.'],
            ]);
        }

        $values = json_decode($decoded, true);

        if (! is_array($values)) {
            throw ValidationException::withMessages([
                'cursor' => ['The cursor is invalid.'],
            ]);
        }

        return $values;
    }
}
