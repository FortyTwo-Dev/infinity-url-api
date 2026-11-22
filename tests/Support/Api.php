<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * Every literal of the public contract a test needs, defined once.
 */
final class Api
{
    public const BASE = 'https://api.url.infinityproject.fr/v1';

    public const REGISTER = self::BASE.'/auth/register';

    public const LOGIN = self::BASE.'/auth/login';

    public const LOGOUT = self::BASE.'/auth/logout';

    public const LOGOUT_ALL = self::BASE.'/auth/logout-all';

    public const REFRESH = self::BASE.'/auth/refresh';

    public const VERIFY_EMAIL = self::BASE.'/auth/verify-email';

    public const RESEND_VERIFICATION = self::BASE.'/auth/verification/resend';

    public const PASSWORD_FORGOT = self::BASE.'/auth/password/forgot';

    public const PASSWORD_RESET = self::BASE.'/auth/password/reset';

    public const TWO_FACTOR_ENABLE = self::BASE.'/auth/2fa/enable';

    public const TWO_FACTOR_CONFIRM = self::BASE.'/auth/2fa/confirm';

    public const TWO_FACTOR_VERIFY = self::BASE.'/auth/2fa/verify';

    public const TWO_FACTOR_DISABLE = self::BASE.'/auth/2fa/disable';

    public const TWO_FACTOR_RECOVERY_CODES = self::BASE.'/auth/2fa/recovery-codes';

    public const ME = self::BASE.'/me';

    public const ME_PASSWORD = self::BASE.'/me/password';

    public const WORKSPACES = self::BASE.'/workspaces';

    public const LINKS = self::BASE.'/links';

    public const TOKENS = self::BASE.'/tokens';

    /**
     * @param  array<string, string|int>  $query
     */
    public static function linkStats(string $publicId, array $query = []): string
    {
        $url = self::LINKS.'/'.rawurlencode($publicId).'/stats';

        return $query === [] ? $url : $url.'?'.http_build_query($query);
    }

    /**
     * @param  array<string, string|int>  $query
     */
    public static function linkStatsCsv(string $publicId, array $query = []): string
    {
        $url = self::LINKS.'/'.rawurlencode($publicId).'/stats.csv';

        return $query === [] ? $url : $url.'?'.http_build_query($query);
    }

    public const PROBLEM_CONTENT_TYPE = 'application/problem+json';
}
