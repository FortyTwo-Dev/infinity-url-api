<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Exceptions\ProblemException;
use App\Mail\ResetPassword;
use App\Models\User;
use App\Services\EmailHasher;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

final class RequestPasswordReset
{
    public function handle(string $email): void
    {
        $user = User::query()
            ->where('email_hash', EmailHasher::hash($email))
            ->first();

        if ($user === null) {
            return;
        }

        $secret = Str::random(48);

        try {
            Cache::put(
                'password-reset:'.$user->public_id,
                hash('sha256', $secret),
                now()->addMinutes((int) config('identity.reset_minutes')),
            );
        } catch (Throwable) {
            throw ProblemException::serviceUnavailable();
        }

        $url = rtrim((string) config('identity.dashboard_url'), '/')
            .'/reset?token='.urlencode($user->public_id.'.'.$secret);

        try {
            Mail::to((string) $user->email)->send(new ResetPassword($url));
        } catch (Throwable) {
            Log::error('Password reset email delivery failed', [
                'user' => $user->public_id,
            ]);
        }
    }
}
