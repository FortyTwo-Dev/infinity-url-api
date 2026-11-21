<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Models\User;
use App\Services\RecoveryCodeService;
use App\Services\TotpService;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

final class DisableTotp
{
    public function __construct(
        private readonly TotpService $totp,
        private readonly RecoveryCodeService $recoveryCodes,
    ) {}

    public function handle(User $user, string $password, string $code): void
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

        $user->forceFill([
            'totp_secret' => null,
            'totp_confirmed_at' => null,
        ])->save();

        $this->recoveryCodes->deleteAll($user);
        // The replay key is deliberately not forgotten: a concurrent request
        // already holding the old secret must still see the consumed step.
        // Its 120-second TTL is the bounded cleanup.
    }
}
