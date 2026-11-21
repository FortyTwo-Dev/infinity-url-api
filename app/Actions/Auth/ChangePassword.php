<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Models\User;
use App\Services\TokenService;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

final class ChangePassword
{
    public function __construct(private readonly TokenService $tokens) {}

    public function handle(
        User $user,
        string $currentPassword,
        string $password,
        ?string $refreshCookie,
    ): void {
        if (! Hash::check($currentPassword, $user->getAuthPassword())) {
            throw ValidationException::withMessages([
                'current_password' => ['The current password is incorrect.'],
            ]);
        }

        $user->forceFill(['password_hash' => $password])->save();

        $family = $refreshCookie === null
            ? null
            : $this->tokens->familyFor($refreshCookie);

        $this->tokens->revokeOtherRefresh($user, $family);
    }
}
