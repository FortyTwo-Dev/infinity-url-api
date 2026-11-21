<?php

use App\Services\EmailHasher;
use Tests\TestCase;

const BLIND_INDEX_EMAIL = 'user@example.com';

uses(TestCase::class);

it('normalizes an email before hashing it', function () {
    $hasher = app(EmailHasher::class);

    expect($hasher->hash('  User@Example.COM '))
        ->toBe($hasher->hash(BLIND_INDEX_EMAIL));
});

it('derives the blind index from the identity key, not from app.key', function () {
    $hasher = app(EmailHasher::class);
    $before = $hasher->hash(BLIND_INDEX_EMAIL);

    config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
    app()->forgetInstance(EmailHasher::class);

    expect(app(EmailHasher::class)->hash(BLIND_INDEX_EMAIL))->toBe($before);
});

it('configures a blind index key that differs from app.key', function () {
    expect(config('identity.blind_index_key'))
        ->toBeString()
        ->not->toBeEmpty()
        ->not->toBe(config('app.key'));
});

it('throws when the blind index key is empty', function () {
    config(['identity.blind_index_key' => '']);
    app()->forgetInstance(EmailHasher::class);

    expect(fn () => app(EmailHasher::class)->hash(BLIND_INDEX_EMAIL))
        ->toThrow(RuntimeException::class);
});

it('returns sixty-four lowercase hexadecimal characters', function () {
    expect(app(EmailHasher::class)->hash(BLIND_INDEX_EMAIL))
        ->toMatch('/^[0-9a-f]{64}$/');
});
