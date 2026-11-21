<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Models\User;
use App\Services\RecoveryCodeService;
use App\Services\TotpService;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

final class RegenerateRecoveryCodes
{
    public function __construct(
        private readonly TotpService $totp,
        private readonly RecoveryCodeService $recoveryCodes,
    ) {}

    /**
     * @return array{recovery_codes: list<string>}
     */
    public function handle(User $user, string $password, string $code): array
    {
        if (! Hash::check($password, $user->getAuthPassword())) {
            throw ValidationException::withMessages([
                'password' => ['The password is incorrect.'],
            ]);
        }

        if (! $user->hasTotp() || $user->totp_secret === null) {
            throw ValidationException::withMessages([
                'code' => ['Two-factor authentication is not enabled.'],
            ]);
        }

        $this->totp->verify($user, $user->totp_secret, $code);

        return ['recovery_codes' => $this->recoveryCodes->regenerate($user)];
    }
}
