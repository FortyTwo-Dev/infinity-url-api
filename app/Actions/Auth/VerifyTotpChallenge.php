<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Exceptions\ProblemException;
use App\Models\User;
use App\Services\RecoveryCodeService;
use App\Services\SignedPayload;
use App\Services\TokenService;
use App\Services\TotpService;
use App\Support\LoginResult;
use Illuminate\Validation\ValidationException;

final class VerifyTotpChallenge
{
    public function __construct(
        private readonly SignedPayload $payloads,
        private readonly TokenService $tokens,
        private readonly TotpService $totp,
        private readonly RecoveryCodeService $recoveryCodes,
    ) {}

    public function handle(string $challenge, ?string $code, ?string $recoveryCode): LoginResult
    {
        $publicId = $this->payloads->resolve($challenge, 'totp');

        $user = $publicId === null
            ? null
            : User::query()->where('public_id', $publicId)->first();

        if ($user === null || ! $user->hasTotp() || $user->totp_secret === null) {
            throw ValidationException::withMessages([
                'challenge' => ['The challenge is invalid or has expired.'],
            ]);
        }

        if ($code !== null) {
            $this->totp->verify($user, $user->totp_secret, $code);
        } elseif ($recoveryCode !== null) {
            if (! $this->recoveryCodes->consume($user, $recoveryCode)) {
                throw ProblemException::recoveryCodeInvalid();
            }
        } else {
            throw ValidationException::withMessages([
                'code' => ['A TOTP code or a recovery code is required.'],
            ]);
        }

        return LoginResult::authenticated($this->tokens->issueSession($user), $user);
    }
}
