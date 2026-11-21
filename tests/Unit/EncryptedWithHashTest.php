<?php

use App\Models\User;
use App\Services\EmailHasher;
use Tests\TestCase;

uses(TestCase::class);

it('writes a ciphertext and the blind index when an email is set', function () {
    $user = new User;
    $user->email = 'user@example.com';

    $attributes = $user->getAttributes();

    expect($attributes['email'])->not->toBe('user@example.com')
        ->and($attributes['email_hash'])->toBe(app(EmailHasher::class)->hash('user@example.com'))
        ->and($user->email)->toBe('user@example.com');
});

it('does not rewrite the ciphertext when the same email is set again', function () {
    $user = new User;
    $user->email = 'user@example.com';
    $user->syncOriginal();

    $ciphertext = $user->getAttributes()['email'];
    $hash = $user->getAttributes()['email_hash'];

    $user->email = '  User@Example.COM ';

    expect($user->isDirty('email'))->toBeFalse()
        ->and($user->isDirty('email_hash'))->toBeFalse()
        ->and($user->getAttributes()['email'])->toBe($ciphertext)
        ->and($user->getAttributes()['email_hash'])->toBe($hash);
});

it('changes both the ciphertext and the hash for a different email', function () {
    $user = new User;
    $user->email = 'first@example.com';

    $ciphertext = $user->getAttributes()['email'];
    $hash = $user->getAttributes()['email_hash'];

    $user->email = 'second@example.com';

    expect($user->getAttributes()['email'])->not->toBe($ciphertext)
        ->and($user->getAttributes()['email_hash'])->not->toBe($hash)
        ->and($user->getAttributes()['email_hash'])->toBe(app(EmailHasher::class)->hash('second@example.com'))
        ->and($user->email)->toBe('second@example.com');
});

it('writes null for the ciphertext and the hash when the email is null', function () {
    $user = new User;
    $user->email = 'user@example.com';

    $user->email = null;

    $attributes = $user->getAttributes();

    expect(array_key_exists('email_hash', $attributes))->toBeTrue()
        ->and($attributes['email'])->toBeNull()
        ->and($attributes['email_hash'])->toBeNull()
        ->and($user->email)->toBeNull();
});
