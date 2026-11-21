<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Mail\VerifyEmail;
use App\Models\User;
use App\Services\SignedPayload;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

final class SendVerificationEmail
{
    public function __construct(private readonly SignedPayload $payloads) {}

    public function handle(User $user): void
    {
        $token = $this->payloads->issue(
            'verify',
            (string) $user->public_id,
            (int) config('identity.verification_hours') * 3600,
        );

        $url = rtrim((string) config('identity.dashboard_url'), '/')
            .'/verify?token='.urlencode($token);

        try {
            Mail::to((string) $user->email)->send(new VerifyEmail($url));
        } catch (Throwable) {
            Log::error('Verification email delivery failed', [
                'user' => $user->public_id,
            ]);
        }
    }
}
