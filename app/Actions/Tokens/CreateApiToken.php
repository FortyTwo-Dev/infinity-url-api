<?php

declare(strict_types=1);

namespace App\Actions\Tokens;

use App\Models\PersonalAccessToken;
use App\Models\User;
use App\Services\TokenService;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\NewAccessToken;
use Laravel\Sanctum\TransientToken;

final class CreateApiToken
{
    public function __construct(private readonly TokenService $tokens) {}

    /**
     * @param  list<string>  $scopes
     */
    public function handle(
        User $user,
        string $name,
        array $scopes,
        ?string $expiresAt,
    ): NewAccessToken {
        $current = $user->currentAccessToken();

        $abilities = match (true) {
            $current instanceof PersonalAccessToken => $current->abilities ?? [],
            $current instanceof TransientToken => ['*'],
            default => ['*'],
        };

        if (! in_array('*', $abilities, true)) {
            foreach ($scopes as $scope) {
                if (! in_array($scope, $abilities, true)) {
                    throw ValidationException::withMessages([
                        'scopes' => ['A token cannot grant a scope it does not hold.'],
                    ]);
                }
            }
        }

        return $this->tokens->createApiToken(
            $user,
            $name,
            $scopes,
            $expiresAt === null ? null : CarbonImmutable::parse($expiresAt)->utc(),
        );
    }
}
