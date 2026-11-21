<?php

declare(strict_types=1);

namespace App\Support;

use Symfony\Component\HttpFoundation\Cookie;

final class RefreshCookie
{
    public const NAME = '__Host-iurl_refresh';

    public static function make(string $token): Cookie
    {
        return cookie(
            self::NAME,
            $token,
            (int) config('identity.tokens.refresh_days') * 24 * 60,
            '/',
            null,
            true,
            true,
            false,
            'lax',
        );
    }

    public static function forget(): Cookie
    {
        return cookie(self::NAME, '', -1, '/', null, true, true, false, 'lax');
    }
}
