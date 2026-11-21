<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Models\User;
use App\Services\TokenService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

final class DeleteAccount
{
    public function __construct(private readonly TokenService $tokens) {}

    public function handle(User $user, string $password): void
    {
        if (! Hash::check($password, $user->getAuthPassword())) {
            throw ValidationException::withMessages([
                'password' => ['The password is incorrect.'],
            ]);
        }

        DB::transaction(function () use ($user): void {
            $this->tokens->revokeAll($user);
            $user->delete();
        });
    }
}
