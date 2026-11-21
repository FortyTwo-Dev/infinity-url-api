<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Exceptions\ProblemException;
use App\Models\User;
use App\Services\EmailHasher;
use App\Services\SignedPayload;
use App\Services\TokenService;
use App\Support\LoginResult;
use Illuminate\Support\Facades\Hash;

final class LoginUser
{
    public function __construct(
        private readonly TokenService $tokens,
        private readonly SignedPayload $payloads,
        private readonly PurgeDeletedUsers $purge,
    ) {}

    public function handle(string $email, string $password): LoginResult
    {
        $user = User::withTrashed()
            ->where('email_hash', EmailHasher::hash($email))
            ->first();

        if ($user === null) {
            // Burn the same work factor as a real check to limit timing leaks.
            Hash::make($password);
            throw ProblemException::unauthorized();
        }

        if (! Hash::check($password, $user->getAuthPassword())) {
            throw ProblemException::unauthorized();
        }

        if ($user->trashed()) {
            if ($user->deleted_at === null || $user->deleted_at->lte(now()->subDays(30))) {
                $this->purge->purge($user);
                throw ProblemException::unauthorized();
            }

            $user->restore();
        }

        if ($user->hasTotp()) {
            $challenge = $this->payloads->issue(
                'totp',
                (string) $user->public_id,
                (int) config('identity.totp.challenge_minutes') * 60,
            );

            return LoginResult::totpRequired($challenge);
        }

        return LoginResult::authenticated($this->tokens->issueSession($user), $user);
    }
}
