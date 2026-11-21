<?php

use App\Models\User;
use App\Models\Workspace;
use App\Services\EmailHasher;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

const HASH_COLUMNS_API = 'https://api.url.infinityproject.fr';

function hashColumnsUser(): User
{
    $workspace = Workspace::factory()->create(['name' => 'My workspace']);
    $user = User::factory()->create();
    $workspace->members()->attach($user->id, ['role' => 'owner']);
    Sanctum::actingAs($user, ['me:read']);

    return $user;
}

/**
 * @return array<int, string>
 */
function hashColumnsJsonKeys(mixed $value): array
{
    if (! is_array($value)) {
        return [];
    }

    $keys = [];

    foreach ($value as $key => $item) {
        if (is_string($key)) {
            $keys[] = $key;
        }

        $keys = [...$keys, ...hashColumnsJsonKeys($item)];
    }

    return $keys;
}

/**
 * @return array<int, string>
 */
function hashColumnsForbiddenKeys(): array
{
    return ['email_hash', 'password_hash', 'totp_secret', 'code_hash', 'used_at'];
}

it('exposes no hash or secret key on the account endpoint', function () {
    hashColumnsUser();

    $response = $this->getJson(HASH_COLUMNS_API.'/v1/me');
    $response->assertOk();

    $keys = hashColumnsJsonKeys($response->json());

    foreach (hashColumnsForbiddenKeys() as $forbidden) {
        expect($keys)->not->toContain($forbidden);
    }
});

it('exposes no hash or secret key on the personal data export', function () {
    hashColumnsUser();

    $response = $this->getJson(HASH_COLUMNS_API.'/v1/me/export');
    $response->assertOk();

    $keys = hashColumnsJsonKeys($response->json());

    foreach (hashColumnsForbiddenKeys() as $forbidden) {
        expect($keys)->not->toContain($forbidden);
    }
});

it('ignores a client supplied email_hash on registration', function () {
    $email = 'hash-injection@example.com';
    $injected = str_repeat('f', 64);

    $response = $this->postJson(HASH_COLUMNS_API.'/v1/auth/register', [
        'email' => $email,
        'password' => 'Password123!',
        'email_hash' => $injected,
    ]);

    $response->assertCreated();

    $expected = app(EmailHasher::class)->hash($email);
    $stored = DB::table('users')->where('email_hash', $expected)->value('email_hash');

    expect($stored)->toBe($expected)
        ->not->toBe($injected);
});
