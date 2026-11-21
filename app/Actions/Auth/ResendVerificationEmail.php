<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Models\User;
use App\Services\EmailHasher;

final class ResendVerificationEmail
{
    public function __construct(private readonly SendVerificationEmail $sendVerification) {}

    public function handle(string $email): void
    {
        $user = User::query()
            ->where('email_hash', EmailHasher::hash($email))
            ->first();

        if ($user !== null && ! $user->isVerified()) {
            $this->sendVerification->handle($user);
        }
    }
}
