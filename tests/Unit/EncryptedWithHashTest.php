<?php

use App\Casts\EncryptedWithHash;
use App\Models\User;
use App\Services\EmailHasher;
use Illuminate\Support\Facades\Crypt;
use Tests\TestCase;

const ENCRYPTED_HASH_EMAIL = 'user@example.com';
const ENCRYPTED_HASH_SECOND_EMAIL = 'second@example.com';
const ENCRYPTED_HASH_NEW_EMAIL = 'new@example.com';

uses(TestCase::class);

it('writes a ciphertext and the blind index when an email is set', function () {
    $user = new User;
    $user->email = ENCRYPTED_HASH_EMAIL;

    $attributes = $user->getAttributes();

    expect($attributes['email'])->not->toBe(ENCRYPTED_HASH_EMAIL)
        ->and($attributes['email_hash'])->toBe(app(EmailHasher::class)->hash(ENCRYPTED_HASH_EMAIL))
        ->and($user->email)->toBe(ENCRYPTED_HASH_EMAIL);
});

it('does not rewrite the ciphertext when the same email is set again', function () {
    $user = new User;
    $user->email = ENCRYPTED_HASH_EMAIL;
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

    $user->email = ENCRYPTED_HASH_SECOND_EMAIL;

    expect($user->getAttributes()['email'])->not->toBe($ciphertext)
        ->and($user->getAttributes()['email_hash'])->not->toBe($hash)
        ->and($user->getAttributes()['email_hash'])->toBe(app(EmailHasher::class)->hash(ENCRYPTED_HASH_SECOND_EMAIL))
        ->and($user->email)->toBe(ENCRYPTED_HASH_SECOND_EMAIL);
});

it('writes null for the ciphertext and the hash when the email is null', function () {
    $user = new User;
    $user->email = ENCRYPTED_HASH_EMAIL;

    $user->email = null;

    $attributes = $user->getAttributes();

    expect(array_key_exists('email_hash', $attributes))->toBeTrue()
        ->and($attributes['email'])->toBeNull()
        ->and($attributes['email_hash'])->toBeNull()
        ->and($user->email)->toBeNull();
});

it('rewrites an attribute that cannot be decrypted', function () {
    $attributes = (new EncryptedWithHash)->set(
        new User,
        'email',
        ENCRYPTED_HASH_NEW_EMAIL,
        ['email' => 'not-a-ciphertext'],
    );

    expect($attributes['email'])->toBeString();
    expect(Crypt::decryptString($attributes['email']))->toBe(ENCRYPTED_HASH_NEW_EMAIL);
    expect($attributes['email_hash'])->toBe(app(EmailHasher::class)->hash(ENCRYPTED_HASH_NEW_EMAIL));
});

it('compares plaintexts rather than ciphertexts', function () {
    $cast = new EncryptedWithHash;
    $user = new User;

    $first = Crypt::encryptString('same@example.com');
    $second = Crypt::encryptString('same@example.com');

    expect($cast->compare($user, 'email', $first, $second))->toBeTrue();
    expect($cast->compare(
        $user,
        'email',
        Crypt::encryptString('a@example.com'),
        Crypt::encryptString('b@example.com'),
    ))->toBeFalse();
});

it('compares nulls and undecryptable values', function () {
    $cast = new EncryptedWithHash;
    $user = new User;
    $ciphertext = Crypt::encryptString('a@example.com');

    expect($cast->compare($user, 'email', null, null))->toBeTrue();
    expect($cast->compare($user, 'email', null, $ciphertext))->toBeFalse();
    expect($cast->compare($user, 'email', $ciphertext, null))->toBeFalse();
    expect($cast->compare($user, 'email', 'not-a-ciphertext', $ciphertext))->toBeFalse();
});
