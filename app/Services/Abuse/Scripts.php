<?php

declare(strict_types=1);

namespace App\Services\Abuse;

/**
 * Unicode script detection.
 *
 * A domain that mixes two alphabets inside one label is a homograph attack:
 * `lаposte.fr` with a Cyrillic `а` looks exactly like `laposte.fr`. Han,
 * Hiragana and Katakana are grouped: a Japanese label legitimately mixes them.
 */
final class Scripts
{
    private const GROUPS = [
        'latin' => '/\p{Latin}/u',
        'cyrillic' => '/\p{Cyrillic}/u',
        'greek' => '/\p{Greek}/u',
        'armenian' => '/\p{Armenian}/u',
        'hebrew' => '/\p{Hebrew}/u',
        'arabic' => '/\p{Arabic}/u',
        'devanagari' => '/\p{Devanagari}/u',
        'thai' => '/\p{Thai}/u',
        'hangul' => '/\p{Hangul}/u',
        'cjk' => '/[\p{Han}\p{Hiragana}\p{Katakana}]/u',
    ];

    public function isMixed(string $host): bool
    {
        $host = rtrim(strtolower(trim($host)), '.');

        if ($host === '') {
            return false;
        }

        $unicode = $this->unicode($host);

        foreach (explode('.', $unicode) as $label) {
            if ($this->scriptsIn($label) > 1) {
                return true;
            }
        }

        return false;
    }

    public function unicode(string $host): string
    {
        if (! str_contains($host, 'xn--')) {
            return $host;
        }

        $decoded = idn_to_utf8($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);

        return is_string($decoded) && $decoded !== '' ? $decoded : $host;
    }

    public function ascii(string $host): ?string
    {
        $ascii = idn_to_ascii(rtrim(strtolower(trim($host)), '.'), IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);

        return is_string($ascii) && $ascii !== '' ? $ascii : null;
    }

    private function scriptsIn(string $label): int
    {
        $count = 0;

        foreach (self::GROUPS as $pattern) {
            if (preg_match($pattern, $label) === 1) {
                $count++;
            }
        }

        return $count;
    }
}
