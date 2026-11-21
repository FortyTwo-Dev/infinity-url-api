<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Exceptions\ProblemException;
use App\Models\User;
use App\Services\RecoveryCodeService;
use App\Services\TotpService;
use Illuminate\Validation\ValidationException;

final class ConfirmTotp
{
    public function __construct(
        private readonly TotpService $totp,
        private readonly RecoveryCodeService $recoveryCodes,
    ) {}

    /**
     * @return array{recovery_codes: list<string>}
     */
    public function handle(User $user, string $code): array
    {
        if ($user->hasTotp()) {
            throw ProblemException::totpAlreadyEnabled();
        }

        if ($user->totp_secret === null) {
            throw ValidationException::withMessages([
                'code' => ['Two-factor authentication has not been enabled.'],
            ]);
        }

        $this->totp->verify($user, $user->totp_secret, $code);

        $user->forceFill(['totp_confirmed_at' => now()])->save();

        return ['recovery_codes' => $this->recoveryCodes->regenerate($user)];
    }
}
