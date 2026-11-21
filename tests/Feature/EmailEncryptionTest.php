<?php

use App\Models\User;
use App\Services\EmailHasher;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

it('stores the email encrypted and its blind index', function () {
    $user = User::factory()->create();
    $plain = $user->email;

    $raw = DB::table('users')->where('id', $user->id)->value('email');
    $hash = DB::table('users')->where('id', $user->id)->value('email_hash');

    expect($raw)->not->toBe($plain)
        ->and(Crypt::decryptString($raw))->toBe($plain)
        ->and($user->email)->toBe($plain)
        ->and($hash)->toBe(app(EmailHasher::class)->hash($plain));
});

it('does not rewrite the email or bump updated_at when an unchanged email is saved', function () {
    $user = User::factory()->create();
    $before = DB::table('users')->where('id', $user->id)->first();

    try {
        $this->travelTo(now()->addMinutes(5));

        $user = User::query()->findOrFail($user->id);
        $user->email = $user->email;
        $user->save();
    } finally {
        $this->travelBack();
    }

    $after = DB::table('users')->where('id', $user->id)->first();

    expect($after->email)->toBe($before->email)
        ->and($after->email_hash)->toBe($before->email_hash)
        ->and($after->updated_at)->toBe($before->updated_at);
});

it('rejects a second user with the same email through the blind index', function () {
    $user = User::factory()->create(['email' => 'duplicate@example.com']);

    $row = (array) DB::table('users')->where('id', $user->id)->first();
    unset($row['id']);
    $row['public_id'] = (string) Str::ulid();

    $rejected = false;

    try {
        DB::table('users')->insert($row);
    } catch (QueryException $exception) {
        $rejected = true;

        expect($exception->getMessage())
            ->toContain('email_hash')
            ->not->toContain('users_email_unique');
    }

    expect($rejected)->toBeTrue();
    expect(DB::table('users')->where('email_hash', $user->email_hash)->count())->toBe(1);
});
