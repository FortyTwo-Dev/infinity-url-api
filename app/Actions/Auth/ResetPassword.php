<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Exceptions\ProblemException;
use App\Models\User;
use App\Services\TokenService;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

final class ResetPassword
{
    public function __construct(private readonly TokenService $tokens) {}

    public function handle(string $token, string $password): void
    {
        [$publicId, $secret] = $this->parse($token);
        $lock = $this->lock($publicId);

        try {
            $lock->block(2, fn () => $this->consumeAndUpdate($publicId, $secret, $password));
        } catch (Throwable $exception) {
            if ($exception instanceof ValidationException || $exception instanceof ProblemException) {
                throw $exception;
            }

            throw ProblemException::serviceUnavailable();
        }
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function parse(string $token): array
    {
        $parts = explode('.', $token, 2);

        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            throw $this->invalidToken();
        }

        return [$parts[0], $parts[1]];
    }

    private function lock(string $publicId): Lock
    {
        try {
            return Cache::lock('password-reset:'.$publicId.':lock', 5);
        } catch (Throwable) {
            throw ProblemException::serviceUnavailable();
        }
    }

    private function consumeAndUpdate(string $publicId, string $secret, string $password): void
    {
        $stored = $this->stored($publicId);

        if (! is_string($stored) || ! hash_equals($stored, hash('sha256', $secret))) {
            throw $this->invalidToken();
        }

        $user = User::query()->where('public_id', $publicId)->first();

        if ($user === null) {
            throw $this->invalidToken();
        }

        $this->forget($publicId);

        DB::transaction(function () use ($user, $password): void {
            $user->forceFill(['password_hash' => $password])->save();
            $this->tokens->revokeAllRefresh($user);
        });
    }

    private function stored(string $publicId): mixed
    {
        try {
            return Cache::get('password-reset:'.$publicId);
        } catch (Throwable) {
            throw ProblemException::serviceUnavailable();
        }
    }

    private function forget(string $publicId): void
    {
        try {
            Cache::forget('password-reset:'.$publicId);
        } catch (Throwable) {
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
