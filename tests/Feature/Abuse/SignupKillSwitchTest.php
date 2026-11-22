<?php

use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Tests\Support\Api;

it('refuses registrations when the kill switch is closed', function () {
    config(['identity.signups_open' => false]);
    Mail::fake();

    $this->postJson(Api::REGISTER, [
        'email' => 'closed@example.com',
        'password' => 'correct horse battery',
    ])->assertStatus(403)->assertJsonPath('code', 'signups_closed');

    expect(User::query()->count())->toBe(0);
    Mail::assertNothingSent();
});

it('accepts registrations when the kill switch is open', function () {
    config(['identity.signups_open' => true]);
    Mail::fake();

    $this->postJson(Api::REGISTER, [
        'email' => 'open@example.com',
        'password' => 'correct horse battery',
    ])->assertCreated();

    expect(User::query()->count())->toBe(1);
});
