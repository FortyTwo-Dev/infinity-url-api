<?php

use App\Mail\VerifyEmail;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

it('verifies an address from the emailed token and stays idempotent on replay', function () {
    Mail::fake();

    $user = User::factory()->unverified()->create(['email' => 'verify@example.com']);

    $this->requestVerification('verify@example.com');
    $token = $this->verificationToken();

    $response = $this->postJson('https://api.url.infinityproject.fr/v1/auth/verify-email', [
        'token' => $token,
    ]);

    $response->assertStatus(200);
    expect((string) $response->getContent())->toBe('');
    expect($user->fresh()->email_verified_at)->not->toBeNull();

    $this->postJson('https://api.url.infinityproject.fr/v1/auth/verify-email', [
        'token' => $token,
    ])->assertStatus(200);

    expect($user->fresh()->email_verified_at)->not->toBeNull();
});

it('rejects a tampered verification token', function () {
    Mail::fake();

    User::factory()->unverified()->create(['email' => 'tampered@example.com']);

    $this->requestVerification('tampered@example.com');
    $token = $this->verificationToken();

    $response = $this->postJson('https://api.url.infinityproject.fr/v1/auth/verify-email', [
        'token' => $token.'x',
    ]);

    $this->assertProblem($response, 422, 'validation_failed');
    expect($response->json('errors'))->toHaveKey('token');
});

it('rejects a verification token older than twenty-four hours', function () {
    Mail::fake();

    $user = User::factory()->unverified()->create(['email' => 'expired@example.com']);

    $this->requestVerification('expired@example.com');
    $token = $this->verificationToken();

    try {
        $this->travel(25)->hours();

        $response = $this->postJson('https://api.url.infinityproject.fr/v1/auth/verify-email', [
            'token' => $token,
        ]);

        $this->assertProblem($response, 422, 'validation_failed');
        expect($response->json('errors'))->toHaveKey('token');
        expect($user->fresh()->email_verified_at)->toBeNull();
    } finally {
        $this->travelBack();
    }
});

it('resends a verification link to an unverified address', function () {
    Mail::fake();

    User::factory()->unverified()->create(['email' => 'resend@example.com']);

    $this->requestVerification('resend@example.com');

    Mail::assertSent(VerifyEmail::class, fn (VerifyEmail $mail): bool => $mail->hasTo('resend@example.com'));
});

it('answers 202 without sending anything for an unknown or verified address', function () {
    Mail::fake();

    User::factory()->create(['email' => 'already@example.com']);

    $this->requestVerification('nobody@example.com');
    $this->requestVerification('already@example.com');

    Mail::assertNothingSent();
});
