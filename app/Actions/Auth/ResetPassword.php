<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Exceptions\ProblemException;
use App\Models\User;
use App\Services\TokenService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

final class ResetPassword
{
    public function __construct(private readonly TokenService $tokens) {}

    public function handle(string $token, string $password): void
    {
        $parts = explode('.', $token, 2);

        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            throw $this->invalidToken();
        }

        [$publicId, $secret] = $parts;

        try {
            $lock = Cache::lock('password-reset:'.$publicId.':lock', 5);
        } catch (Throwable) {
            throw ProblemException::serviceUnavailable();
        }

        try {
            $lock->block(2, function () use ($publicId, $secret, $password): void {
                try {
                    $stored = Cache::get('password-reset:'.$publicId);
                } catch (Throwable) {
                    throw ProblemException::serviceUnavailable();
                }

                if (! is_string($stored) || ! hash_equals($stored, hash('sha256', $secret))) {
                    throw $this->invalidToken();
                }

                $user = User::query()->where('public_id', $publicId)->first();

                if ($user === null) {
                    throw $this->invalidToken();
                }

                try {
                    Cache::forget('password-reset:'.$publicId);
                } catch (Throwable) {
                    throw ProblemException::serviceUnavailable();
                }

                DB::transaction(function () use ($user, $password): void {
                    $user->forceFill(['password_hash' => $password])->save();
                    $this->tokens->revokeAllRefresh($user);
                });
            });
        } catch (Throwable $exception) {
            if ($exception instanceof ValidationException || $exception instanceof ProblemException) {
                throw $exception;
            }

            throw ProblemException::serviceUnavailable();
        }
    }

    private function invalidToken(): ValidationException
    {
        return ValidationException::withMessages([
            'token' => ['The reset token is invalid or has expired.'],
        ]);
    }
}
