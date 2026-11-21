<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\ProblemException;
use App\Models\PersonalAccessToken;
use App\Models\User;
use App\Support\SessionTokens;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\NewAccessToken;
use Throwable;

final class TokenService
{
    public function issueSession(User $user): SessionTokens
    {
        $familyId = (string) Str::uuid();
        $access = $this->createAccess($user);
        $refresh = $this->createRefresh($user, $familyId);

        return new SessionTokens(
            $access->plainTextToken,
            $refresh->plainTextToken,
            $familyId,
            $this->accessTtlSeconds(),
        );
    }

    public function rotate(string $plainToken): SessionTokens
    {
        $found = $this->refreshToken($plainToken);
        $tokens = $this->transaction($found);

        if (! $tokens instanceof SessionTokens) {
            throw ProblemException::tokenRevoked();
        }

        return $tokens;
    }

    private function refreshToken(string $plainToken): PersonalAccessToken
    {
        $found = PersonalAccessToken::findToken($plainToken);

        if (! $found instanceof PersonalAccessToken
            || $found->type !== 'refresh'
            || ! $found->can('refresh')
            || $found->family_id === null) {
            throw ProblemException::tokenRevoked();
        }

        return $found;
    }

    private function transaction(PersonalAccessToken $found): ?SessionTokens
    {
        try {
            return DB::transaction(fn () => $this->rotateLocked($found));
        } catch (Throwable $exception) {
            if ($exception instanceof ProblemException) {
                throw $exception;
            }

            throw ProblemException::serviceUnavailable();
        }
    }

    private function rotateLocked(PersonalAccessToken $found): ?SessionTokens
    {
        // Serialise the whole family before locking a token row: two replays
        // of different tokens from one family would otherwise each lock their
        // row and deadlock in revokeFamily(). The lock is transaction-scoped.
        DB::select('select pg_advisory_xact_lock(hashtext(?)::bigint)', [$found->family_id]);

        $token = $this->assertRotatable($this->lock($found));

        if ($this->isReplay($token)) {
            return null;
        }

        $user = $this->user($token);
        $token->forceFill(['consumed_at' => now()])->save();

        return $this->issue($user, (string) $token->family_id);
    }

    private function lock(PersonalAccessToken $found): ?PersonalAccessToken
    {
        return PersonalAccessToken::query()
            ->whereKey($found->id)
            ->lockForUpdate()
            ->first();
    }

    private function assertRotatable(?PersonalAccessToken $token): PersonalAccessToken
    {
        if (! $token instanceof PersonalAccessToken
            || $token->type !== 'refresh'
            || ! $token->can('refresh')) {
            throw ProblemException::tokenRevoked();
        }

        return $token;
    }

    private function isReplay(PersonalAccessToken $token): bool
    {
        if ($token->consumed_at !== null) {
            $this->revokeFamily($token->family_id);

            return true;
        }

        if ($token->expires_at !== null && $token->expires_at->isPast()) {
            $this->revokeFamily($token->family_id);

            return true;
        }

        return false;
    }

    private function user(PersonalAccessToken $token): User
    {
        $user = $token->tokenable;

        if (! $user instanceof User || $user->trashed()) {
            throw ProblemException::tokenRevoked();
        }

        return $user;
    }

    private function issue(User $user, string $familyId): SessionTokens
    {
        $access = $this->createAccess($user);
        $refresh = $this->createRefresh($user, $familyId);

        return new SessionTokens(
            $access->plainTextToken,
            $refresh->plainTextToken,
            $familyId,
            $this->accessTtlSeconds(),
        );
    }

    public function familyFor(string $plainToken): ?string
    {
        $token = PersonalAccessToken::findToken($plainToken);

        if (! $token instanceof PersonalAccessToken || $token->type !== 'refresh') {
            return null;
        }

        return $token->family_id;
    }

    public function revokeCurrent(string $plainToken): void
    {
        $token = PersonalAccessToken::findToken($plainToken);

        if ($token instanceof PersonalAccessToken && $token->type === 'refresh') {
            $token->delete();
        }
    }

    public function revokeAllRefresh(User $user): void
    {
        $user->tokens()->where('type', 'refresh')->delete();
    }

    public function revokeOtherRefresh(User $user, ?string $keepFamily): void
    {
        $query = $user->tokens()->where('type', 'refresh');

        if ($keepFamily !== null) {
            $query->where('family_id', '!=', $keepFamily);
        }

        $query->delete();
    }

    public function revokeAll(User $user): void
    {
        $user->tokens()->delete();
    }

    public function revokeFamily(?string $familyId): void
    {
        if ($familyId === null) {
            return;
        }

        PersonalAccessToken::query()->where('family_id', $familyId)->delete();
    }

    /**
     * @param  list<string>  $scopes
     */
    public function createApiToken(
        User $user,
        string $name,
        array $scopes,
        ?CarbonInterface $expiresAt,
    ): NewAccessToken {
        $token = $user->createToken($name, $scopes, $expiresAt);
        $token->accessToken->forceFill(['type' => 'access'])->save();

        return $token;
    }

    /**
     * API tokens only: never dashboard access tokens (`['*']`) or refresh tokens.
     *
     * @return MorphMany<PersonalAccessToken, User>
     */
    public function apiTokens(User $user): MorphMany
    {
        return $user->apiTokens()
            ->where('type', 'access')
            ->where(static function ($query): void {
                $query->whereNull('abilities')
                    ->orWhere('abilities', '!=', '["*"]');
            });
    }

    public function accessTtlSeconds(): int
    {
        return (int) config('identity.tokens.access_minutes') * 60;
    }

    private function createAccess(User $user): NewAccessToken
    {
        $token = $user->createToken(
            'dashboard',
            ['*'],
            now()->addMinutes((int) config('identity.tokens.access_minutes')),
        );

        $token->accessToken->forceFill(['type' => 'access'])->save();

        return $token;
    }

    private function createRefresh(User $user, string $familyId): NewAccessToken
    {
        $token = $user->createToken(
            'refresh',
            ['refresh'],
            now()->addDays((int) config('identity.tokens.refresh_days')),
        );

        $token->accessToken->forceFill([
            'type' => 'refresh',
            'family_id' => $familyId,
        ])->save();

        return $token;
    }
}
