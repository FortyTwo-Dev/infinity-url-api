<?php

use App\Mail\VerifyEmail;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Tests\Support\Api;

const NEWCOMER_EMAIL = 'newcomer@example.com';
const REGISTRATION_PASSWORD = 'correct horse battery';

it('registers a new account with a workspace and sends a verification email', function () {
    Mail::fake();

    $response = $this->postJson(Api::REGISTER, [
        'email' => NEWCOMER_EMAIL,
        'password' => REGISTRATION_PASSWORD,
    ]);

    $response->assertStatus(201);
    expect((string) $response->getContent())->toBe('');
    $response->assertCookieMissing('__Host-iurl_refresh');

    expect(User::count())->toBe(1);

    $user = User::query()->firstOrFail();
    expect($user->email)->toBe(NEWCOMER_EMAIL);
    expect($user->email_verified_at)->toBeNull();

    expect($user->workspaces()->count())->toBe(1);
    $workspace = $user->workspaces()->first();
    expect($workspace->pivot->role)->toBe('owner');
    expect($workspace->name)->toBe('My workspace');

    Mail::assertSentCount(1);
    Mail::assertSent(VerifyEmail::class, fn (VerifyEmail $mail): bool => $mail->hasTo(NEWCOMER_EMAIL));
});

it('answers 201 twice and resends the verification link while the account is unverified', function () {
    Mail::fake();

    $payload = [
        'email' => 'twice@example.com',
        'password' => REGISTRATION_PASSWORD,
    ];

    $this->postJson(Api::REGISTER, $payload)->assertStatus(201);
    $this->postJson(Api::REGISTER, $payload)->assertStatus(201);

    expect(User::count())->toBe(1);
    Mail::assertSentCount(2);
});

it('does not resend a verification link once the account is verified', function () {
    Mail::fake();

    $payload = [
        'email' => 'verified@example.com',
        'password' => REGISTRATION_PASSWORD,
    ];

    $this->postJson(Api::REGISTER, $payload)->assertStatus(201);

    $user = User::query()->firstOrFail();
    $user->forceFill(['email_verified_at' => now()])->save();

    $this->postJson(Api::REGISTER, $payload)->assertStatus(201);

    expect(User::count())->toBe(1);
    Mail::assertSentCount(1);
});

it('does not recreate a soft-deleted account and sends it no verification email', function () {
    Mail::fake();

    $user = User::factory()->create(['email' => 'gone@example.com']);
    $user->delete();

    $this->postJson(Api::REGISTER, [
        'email' => 'gone@example.com',
        'password' => REGISTRATION_PASSWORD,
    ])->assertStatus(201);

    expect(User::withTrashed()->count())->toBe(1);
    expect(User::withTrashed()->firstOrFail()->trashed())->toBeTrue();
    Mail::assertNothingSent();
});

it('rejects an invalid email address', function () {
    Mail::fake();

    $response = $this->postJson(Api::REGISTER, [
        'email' => 'not-an-email',
        'password' => REGISTRATION_PASSWORD,
    ]);

    $response->assertStatus(422);
    expect((string) $response->headers->get('Content-Type'))->toStartWith(Api::PROBLEM_CONTENT_TYPE);
    expect($response->json('code'))->toBe('validation_failed');
    expect($response->json('instance'))->toBe('/v1/auth/register');
    expect($response->json('errors'))->toHaveKey('email');
    expect(User::count())->toBe(0);
    Mail::assertNothingSent();
});

it('requires a password of at least twelve characters', function () {
    Mail::fake();

    $response = $this->postJson(Api::REGISTER, [
        'email' => 'short@example.com',
        'password' => 'elevenchars',
    ]);

    $response->assertStatus(422);
    expect($response->json('code'))->toBe('validation_failed');
    expect($response->json('errors'))->toHaveKey('password');
    expect(User::count())->toBe(0);
    Mail::assertNothingSent();
});

it('answers identically for a new and an existing address', function () {
    Mail::fake();

    $payload = [
        'email' => 'enumeration@example.com',
        'password' => REGISTRATION_PASSWORD,
    ];

    $first = $this->postJson(Api::REGISTER, $payload);
    $first->assertStatus(201);

    User::query()->firstOrFail()->forceFill(['email_verified_at' => now()])->save();

    $second = $this->postJson(Api::REGISTER, $payload);
    $second->assertStatus(201);

    expect($second->getContent())->toBe($first->getContent());
    expect($second->headers->get('Content-Type'))->toBe($first->headers->get('Content-Type'));
    expect($second->headers->get('Location'))->toBeNull();
    expect(User::count())->toBe(1);
});
