<?php

use App\Models\User;
use App\Models\Workspace;
use App\Services\SlugRules;
use Database\Seeders\LinkDomainSeeder;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Support\Api;

function slugValidationWorkspace(): Workspace
{
    test()->seed(LinkDomainSeeder::class);

    $workspace = Workspace::factory()->create();
    $user = User::factory()->create();
    $workspace->members()->attach($user->id, ['role' => 'owner']);
    Sanctum::actingAs($user, ['links:write']);

    return $workspace;
}

it('trims and lowercases a slug', function () {
    expect(app(SlugRules::class)->normalize('  PrOmO  '))->toBe('promo');
});

it('accepts slugs at the length boundaries', function () {
    $rules = app(SlugRules::class);

    expect($rules->isValid('ab'))->toBeTrue()
        ->and($rules->isValid(str_repeat('a', 64)))->toBeTrue()
        ->and($rules->isValid('a'))->toBeFalse()
        ->and($rules->isValid(str_repeat('a', 65)))->toBeFalse();
});

it('rejects separators, spaces and non-ascii characters', function () {
    $rules = app(SlugRules::class);

    expect($rules->isValid('.'))->toBeFalse()
        ->and($rules->isValid('/'))->toBeFalse()
        ->and($rules->isValid('a/b'))->toBeFalse()
        ->and($rules->isValid('..'))->toBeFalse()
        ->and($rules->isValid('has space'))->toBeFalse()
        ->and($rules->isValid('promé'))->toBeFalse();
});

it('stores and returns a custom slug in lowercase', function () {
    slugValidationWorkspace();

    $response = $this->postJson(Api::LINKS, [
        'destination' => 'https://example.com/promo',
        'slug' => 'PrOmO',
    ]);

    $response->assertCreated()
        ->assertJsonPath('slug', 'promo')
        ->assertJsonPath('short_url', 'https://iurl.fr/promo');

    $stored = DB::table('links')->where('public_id', $response->json('id'))->value('slug');

    expect($stored)->toBe('promo');
});

it('rejects a one-character slug', function () {
    slugValidationWorkspace();

    $response = $this->postJson(Api::LINKS, [
        'destination' => 'https://example.com/too-short',
        'slug' => 'a',
    ]);

    $response->assertStatus(422)
        ->assertJsonPath('code', 'validation_failed');

    expect($response->headers->get('Content-Type'))->toContain(Api::PROBLEM_CONTENT_TYPE)
        ->and($response->json('errors.slug'))->not->toBeEmpty();
});

it('rejects a slug containing a space', function () {
    slugValidationWorkspace();

    $response = $this->postJson(Api::LINKS, [
        'destination' => 'https://example.com/space',
        'slug' => 'has space',
    ]);

    $response->assertStatus(422)
        ->assertJsonPath('code', 'validation_failed');

    expect($response->headers->get('Content-Type'))->toContain(Api::PROBLEM_CONTENT_TYPE)
        ->and($response->json('errors.slug'))->not->toBeEmpty();
});
