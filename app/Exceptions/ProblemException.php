<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

final class ProblemException extends RuntimeException
{
    /**
     * @param  array<string, list<string>>  $errors
     */
    private function __construct(
        public readonly int $status,
        public readonly string $problemCode,
        public readonly string $title,
        ?string $detail = null,
        public readonly array $errors = [],
    ) {
        parent::__construct($detail ?? $title);
    }

    public static function slugTaken(string $slug): self
    {
        return new self(
            409,
            'slug_taken',
            'Slug already taken',
            "The slug '".$slug."' is already used on this domain.",
        );
    }

    public static function slugReserved(string $slug): self
    {
        return new self(
            422,
            'slug_reserved',
            'Slug reserved',
            "The slug '".$slug."' is reserved.",
            ['slug' => ['This slug is reserved.']],
        );
    }

    public static function quotaExceeded(int $limit): self
    {
        return new self(
            409,
            'quota_exceeded',
            'Link quota exceeded',
            'The workspace has reached its limit of '.$limit.' active links.',
        );
    }

    public static function emailUnverified(): self
    {
        return new self(
            403,
            'email_unverified',
            'Email address not verified',
            'Verify your email address before creating a link.',
        );
    }

    public static function accountSuspended(): self
    {
        return new self(
            403,
            'account_suspended',
            'Account suspended',
            'This account is suspended: creating a link is not allowed.',
        );
    }

    public static function signupsClosed(): self
    {
        return new self(
            403,
            'signups_closed',
            'Signups closed',
            'New accounts are closed right now.',
        );
    }

    public static function linkBlocked(): self
    {
        return new self(
            403,
            'link_blocked',
            'Link blocked',
            'This link is blocked and cannot be edited.',
        );
    }

    public static function totpAlreadyEnabled(): self
    {
        return new self(
            409,
            'totp_already_enabled',
            'Two-factor authentication already enabled',
            'Two-factor authentication is already enabled on this account.',
        );
    }

    public static function totpInvalid(): self
    {
        return new self(
            422,
            'totp_invalid',
            'Invalid TOTP code',
            'The TOTP code is invalid.',
            ['code' => ['The TOTP code is invalid.']],
        );
    }

    public static function recoveryCodeInvalid(): self
    {
        return new self(
            422,
            'recovery_code_invalid',
            'Invalid recovery code',
            'The recovery code is invalid.',
            ['recovery_code' => ['The recovery code is invalid.']],
        );
    }

    public static function tokenRevoked(): self
    {
        return new self(
            401,
            'token_revoked',
            'Token revoked',
            'The token is no longer valid.',
        );
    }

    public static function destinationBlocked(?string $detail = null): self
    {
        return new self(
            422,
            'destination_blocked',
            'Destination blocked',
            $detail === null
                ? 'The destination is blocked.'
                : 'The destination is blocked: '.$detail,
        );
    }

    public static function destinationPrivateAddress(?string $detail = null): self
    {
        return new self(
            422,
            'destination_private_address',
            'Private address not allowed',
            $detail === null
                ? 'The destination points to a private, loopback or metadata address.'
                : 'The destination points to a private address: '.$detail,
        );
    }

    public static function notFound(): self
    {
        return new self(
            404,
            'not_found',
            'Not found',
            'The requested resource does not exist.',
        );
    }

    public static function linkNotFound(): self
    {
        return new self(
            404,
            'link_not_found',
            'Link not found',
            'The requested link does not exist.',
        );
    }

    public static function unauthorized(): self
    {
        return new self(
            401,
            'unauthorized',
            'Unauthorized',
            'The email address or password is incorrect.',
        );
    }

    public static function serviceUnavailable(): self
    {
        return new self(
            503,
            'server_error',
            'Service unavailable',
            'A required service is unavailable.',
        );
    }
}
