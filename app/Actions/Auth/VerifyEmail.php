<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Models\User;
use App\Services\SignedPayload;
use Illuminate\Validation\ValidationException;

final class VerifyEmail
{
    public function __construct(private readonly SignedPayload $payloads) {}

    public function handle(string $token): void
    {
        $publicId = $this->payloads->resolve($token, 'verify');

        $user = $publicId === null
            ? null
            : User::query()->where('public_id', $publicId)->first();

        if ($user === null) {
            throw ValidationException::withMessages([
                'token' => ['The verification token is invalid or has expired.'],
            ]);
        }

        if (! $user->isVerified()) {
            $user->forceFill(['email_verified_at' => now()])->save();
        }
    }
}
