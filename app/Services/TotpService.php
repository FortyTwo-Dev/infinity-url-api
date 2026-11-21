<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\ProblemException;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use PragmaRX\Google2FA\Google2FA;
use Throwable;

final class TotpService
{
    private readonly Google2FA $google;

    public function __construct()
    {
        $this->google = new Google2FA;
    }

    public function generateSecret(): string
    {
        return $this->google->generateSecretKey();
    }

    public function otpauthUrl(User $user, string $secret): string
    {
        return $this->google->getQRCodeUrl('Infinity URL', $user->email, $secret);
    }

    /**
     * Verify a TOTP code and refuse a replay.
     *
     * The lock serialises two requests presenting the same timestep: without
     * it, both could pass verifyKeyNewer() before either wrote the replay key.
     */
    public function verify(User $user, string $secret, string $code): void
    {
        $key = $this->key($user, $secret);

        try {
            $lock = Cache::lock($key.':lock', 5);
        } catch (Throwable) {
            throw ProblemException::serviceUnavailable();
        }

        try {
            $lock->block(2, function () use ($key, $secret, $code): void {
                try {
                    $last = Cache::get($key);
                } catch (Throwable) {
                    throw ProblemException::serviceUnavailable();
                }

                $timestamp = $this->google->verifyKeyNewer(
                    $secret,
                    $code,
                    is_numeric($last) ? (int) $last : 0,
                    (int) config('identity.totp.window'),
                );

                if ($timestamp === false) {
                    throw ProblemException::totpInvalid();
                }

                try {
                    Cache::put($key, (int) $timestamp, 120);
                } catch (Throwable) {
                    throw ProblemException::serviceUnavailable();
                }
            });
        } catch (Throwable $exception) {
            if ($exception instanceof ProblemException) {
                throw $exception;
            }

            throw ProblemException::serviceUnavailable();
        }
    }

    private function key(User $user, string $secret): string
    {
        return 'totp:'.$user->public_id.':'.substr(hash('sha256', $secret), 0, 16);
    }
}
