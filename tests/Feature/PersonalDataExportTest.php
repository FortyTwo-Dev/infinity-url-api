<?php

use App\Models\Domain;
use App\Models\Link;
use App\Models\User;
use App\Models\Workspace;
use Database\Seeders\LinkDomainSeeder;
use Laravel\Sanctum\Sanctum;

const PERSONAL_DATA_EXPORT_API = 'https://api.url.infinityproject.fr';

/**
 * @param  array<string, mixed>  $value
 * @return array<int, string>
 */
function personalDataExportSortedKeys(array $value): array
{
    $keys = array_keys($value);
    sort($keys);

    return $keys;
}

/**
 * @return array<int, string>
 */
function personalDataExportJsonKeys(mixed $value): array
{
    if (! is_array($value)) {
        return [];
    }

    $keys = [];

    foreach ($value as $key => $item) {
        if (is_string($key)) {
            $keys[] = $key;
        }

        $keys = [...$keys, ...personalDataExportJsonKeys($item)];
    }

    return $keys;
}

it('exports exactly the allowlisted personal data', function () {
    $this->seed(LinkDomainSeeder::class);

    $workspace = Workspace::factory()->create(['name' => 'My workspace']);
    $user = User::factory()->create(['email' => 'owner@example.com']);
    $workspace->members()->attach($user->id, ['role' => 'owner']);
    Sanctum::actingAs($user, ['me:read']);

    $domain = Domain::query()->where('hostname', 'iurl.fr')->firstOrFail();
    $link = Link::factory()->forDomain($domain)->create([
        'workspace_id' => $workspace->id,
        'slug' => 'export1',
        'destination' => 'https://example.com/export',
        'expires_at' => null,
    ]);

    $response = $this->getJson(PERSONAL_DATA_EXPORT_API.'/v1/me/export');
    $response->assertOk();

    $body = $response->json();

    expect(personalDataExportSortedKeys($body))
        ->toBe(['account', 'links', 'statistics', 'workspace']);

    expect(personalDataExportSortedKeys($body['account']))
        ->toBe(['created_at', 'email', 'email_verified', 'id', 'totp_enabled']);

    expect(personalDataExportSortedKeys($body['workspace']))
        ->toBe(['id', 'limits', 'name', 'plan']);

    expect(personalDataExportSortedKeys($body['workspace']['limits']))
        ->toBe(['active_links', 'custom_domains', 'stats_retention_days']);

    expect($body['statistics'])->toBe([]);

    expect($body['account']['id'])->toBe($user->public_id)
        ->and($body['account']['email'])->toBe('owner@example.com')
        ->and($body['account']['email_verified'])->toBeTrue()
        ->and($body['account']['totp_enabled'])->toBeFalse();

    expect($body['workspace']['id'])->toBe($workspace->public_id)
        ->and($body['workspace']['name'])->toBe('My workspace')
        ->and($body['workspace']['plan'])->toBe('free')
        ->and($body['workspace']['limits']['active_links'])->toBe(5)
        ->and($body['workspace']['limits']['custom_domains'])->toBe(0);

    expect($body['links'])->toHaveCount(1);

    $item = $body['links'][0];

    expect(personalDataExportSortedKeys($item))
        ->toBe([
            'created_at',
            'destination',
            'expires_at',
            'id',
            'short_url',
            'slug',
            'status',
            'updated_at',
        ]);

    expect($item['id'])->toBe($link->public_id)
        ->and($item['slug'])->toBe('export1')
        ->and($item['destination'])->toBe('https://example.com/export')
        ->and($item['short_url'])->toBe('https://iurl.fr/export1')
        ->and($item['status'])->toBe('active')
        ->and($item['expires_at'])->toBeNull();

    $keys = personalDataExportJsonKeys($body);

    foreach (['email_hash', 'password_hash', 'totp_secret', 'code_hash', 'used_at'] as $forbidden) {
        expect($keys)->not->toContain($forbidden);
    }
});
