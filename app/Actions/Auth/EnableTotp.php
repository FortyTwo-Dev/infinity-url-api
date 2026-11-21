<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Exceptions\ProblemException;
use App\Models\User;
use App\Services\TotpService;

final class EnableTotp
{
    public function __construct(private readonly TotpService $totp) {}

    /**
     * @return array{secret: string, otpauth_url: string}
     */
    public function handle(User $user): array
    {
        if ($user->hasTotp()) {
            throw ProblemException::totpAlreadyEnabled();
        }

        $secret = $this->totp->generateSecret();

        $user->forceFill([
            'totp_secret' => $secret,
            'totp_confirmed_at' => null,
        ])->save();

        return [
            'secret' => $secret,
            'otpauth_url' => $this->totp->otpauthUrl($user, $secret),
        ];
    }
}
