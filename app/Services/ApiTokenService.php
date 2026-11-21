<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\PersonalAccessToken;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Laravel\Sanctum\NewAccessToken;

/**
 * API tokens, distinct from the dashboard session tokens managed by
 * TokenService.
 */
final class ApiTokenService
{
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
}
