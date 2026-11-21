<?php

use App\Mail\VerifyEmail;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

it('verifies an address from the emailed token and stays idempotent on replay', function () {
    Mail::fake();

    $user = User::factory()->unverified()->create(['email' => 'verify@example.com']);

    $this->postJson('https://api.url.infinityproject.fr/v1/auth/verification/resend', [
        'email' => 'verify@example.com',
    ])->assertStatus(202);

    $url = Mail::sent(VerifyEmail::class)->firstOrFail()->url;
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
    $token = $query['token'] ?? '';

    expect($token)->toBeString()->not->toBe('');

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

    $this->postJson('https://api.url.infinityproject.fr/v1/auth/verification/resend', [
        'email' => 'tampered@example.com',
    ])->assertStatus(202);

    $url = Mail::sent(VerifyEmail::class)->firstOrFail()->url;
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

    $token = $query['token'] ?? '';
    expect($token)->toBeString()->not->toBe('');

    $response = $this->postJson('https://api.url.infinityproject.fr/v1/auth/verify-email', [
        'token' => $token.'x',
    ]);

    $response->assertStatus(422);
    expect((string) $response->headers->get('Content-Type'))->toStartWith('application/problem+json');
    expect($response->json('code'))->toBe('validation_failed');
    expect($response->json('errors'))->toHaveKey('token');
});

it('rejects a verification token older than twenty-four hours', function () {
    Mail::fake();

    $user = User::factory()->unverified()->create(['email' => 'expired@example.com']);

    $this->postJson('https://api.url.infinityproject.fr/v1/auth/verification/resend', [
        'email' => 'expired@example.com',
    ])->assertStatus(202);

    $url = Mail::sent(VerifyEmail::class)->firstOrFail()->url;
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

    $token = $query['token'] ?? '';
    expect($token)->toBeString()->not->toBe('');

    try {
        $this->travel(25)->hours();

        $response = $this->postJson('https://api.url.infinityproject.fr/v1/auth/verify-email', [
            'token' => $token,
        ]);

        $response->assertStatus(422);
        expect($response->json('code'))->toBe('validation_failed');
        expect($response->json('errors'))->toHaveKey('token');
        expect($user->fresh()->email_verified_at)->toBeNull();
    } finally {
        $this->travelBack();
    }
});

it('resends a verification link to an unverified address', function () {
    Mail::fake();

    User::factory()->unverified()->create(['email' => 'resend@example.com']);

    $this->postJson('https://api.url.infinityproject.fr/v1/auth/verification/resend', [
        'email' => 'resend@example.com',
    ])->assertStatus(202);

    Mail::assertSent(VerifyEmail::class, fn (VerifyEmail $mail): bool => $mail->hasTo('resend@example.com'));
});

it('answers 202 without sending anything for an unknown or verified address', function () {
    Mail::fake();

    User::factory()->create(['email' => 'already@example.com']);

    $this->postJson('https://api.url.infinityproject.fr/v1/auth/verification/resend', [
        'email' => 'nobody@example.com',
    ])->assertStatus(202);

    $this->postJson('https://api.url.infinityproject.fr/v1/auth/verification/resend', [
        'email' => 'already@example.com',
    ])->assertStatus(202);

    Mail::assertNothingSent();
});
