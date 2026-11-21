<?php

use App\Models\Domain;
use App\Models\Link;
use App\Models\User;
use App\Models\Workspace;
use Database\Seeders\LinkDomainSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

it('deletes the account, soft deletes it and revokes every token', function () {
    $workspace = Workspace::factory()->create();
    $user = User::factory()->create(['email' => 'delete@example.com']);
    $workspace->members()->attach($user->id, ['role' => 'owner']);

    $token = $user->createToken('dashboard', ['*'])->plainTextToken;

    $response = $this->withToken($token)->deleteJson('https://api.url.infinityproject.fr/v1/me', [
        'password' => 'password',
    ]);

    $response->assertStatus(204);

    $trashed = User::withTrashed()->find($user->id);
    expect($trashed)->not->toBeNull();
    expect($trashed->trashed())->toBeTrue();
    expect(DB::table('personal_access_tokens')->where('tokenable_id', $user->id)->count())->toBe(0);

    $this->app['auth']->forgetGuards();

    expect($this->withToken($token)->getJson('https://api.url.infinityproject.fr/v1/me')->status())->toBe(401);
});

it('keeps the email occupied after a deletion', function () {
    Mail::fake();

    $user = User::factory()->create(['email' => 'occupied@example.com']);
    $user->delete();

    $this->postJson('https://api.url.infinityproject.fr/v1/auth/register', [
        'email' => 'occupied@example.com',
        'password' => 'correct horse battery',
    ])->assertStatus(201);

    expect(User::withTrashed()->count())->toBe(1);
    Mail::assertNothingSent();
});

it('restores a deleted account and its links when logging in within the grace period', function () {
    $this->seed(LinkDomainSeeder::class);
    $domain = Domain::query()->where('hostname', 'iurl.fr')->firstOrFail();

    $workspace = Workspace::factory()->create(['name' => 'My workspace']);
    $user = User::factory()->create(['email' => 'restore@example.com']);
    $workspace->members()->attach($user->id, ['role' => 'owner']);

    Link::factory()->forDomain($domain)->create([
        'workspace_id' => $workspace->id,
        'slug' => 'keepme1',
    ]);

    $token = $user->createToken('dashboard', ['*'])->plainTextToken;

    $this->withToken($token)->deleteJson('https://api.url.infinityproject.fr/v1/me', [
        'password' => 'password',
    ])->assertStatus(204);

    $this->flushHeaders();

    expect(User::find($user->id))->toBeNull();

    $restored = $this->postJson('https://api.url.infinityproject.fr/v1/auth/login', [
        'email' => 'restore@example.com',
        'password' => 'password',
    ]);

    $restored->assertStatus(200);
    expect($restored->json('user.id'))->toBe($user->public_id);

    $again = User::find($user->id);
    expect($again)->not->toBeNull();
    expect($again->trashed())->toBeFalse();

    expect(Link::query()->where('workspace_id', $workspace->id)->count())->toBe(1);
});

it('refuses the login after the grace period and purges the account with slug tombstones', function () {
    $this->seed(LinkDomainSeeder::class);
    $domain = Domain::query()->where('hostname', 'iurl.fr')->firstOrFail();

    $workspace = Workspace::factory()->create();
    $user = User::factory()->create(['email' => 'purged@example.com']);
    $workspace->members()->attach($user->id, ['role' => 'owner']);

    Link::factory()->forDomain($domain)->create([
        'workspace_id' => $workspace->id,
        'slug' => 'purge001',
    ]);

    $token = $user->createToken('dashboard', ['*'])->plainTextToken;

    $this->withToken($token)->deleteJson('https://api.url.infinityproject.fr/v1/me', [
        'password' => 'password',
    ])->assertStatus(204);

    $this->flushHeaders();

    try {
        $this->travel(31)->days();

        $this->postJson('https://api.url.infinityproject.fr/v1/auth/login', [
            'email' => 'purged@example.com',
            'password' => 'password',
        ])->assertStatus(401);

        $this->artisan('users:purge-deleted')->assertSuccessful();

        expect(User::withTrashed()->find($user->id))->toBeNull();
        expect(Workspace::withTrashed()->find($workspace->id))->toBeNull();
        expect(DB::table('links')->where('slug', 'purge001')->count())->toBe(0);
        expect(
            DB::table('slug_tombstones')
                ->where('domain_id', $domain->id)
                ->where('slug', 'purge001')
                ->exists()
        )->toBeTrue();

        $this->get('https://iurl.fr/purge001')->assertStatus(410);
    } finally {
        $this->travelBack();
    }
});

it('invalidates a warmed redirect cache when the purge runs', function () {
    $this->seed(LinkDomainSeeder::class);
    $domain = Domain::query()->where('hostname', 'iurl.fr')->firstOrFail();

    $workspace = Workspace::factory()->create();
    $user = User::factory()->create(['email' => 'warmcache@example.com']);
    $workspace->members()->attach($user->id, ['role' => 'owner']);

    Link::factory()->forDomain($domain)->create([
        'workspace_id' => $workspace->id,
        'slug' => 'warm001',
    ]);

    $this->get('https://iurl.fr/warm001')->assertStatus(302);
    expect(Cache::has('link:iurl.fr:warm001'))->toBeTrue();

    $token = $user->createToken('dashboard', ['*'])->plainTextToken;

    $this->withToken($token)->deleteJson('https://api.url.infinityproject.fr/v1/me', [
        'password' => 'password',
    ])->assertStatus(204);

    $this->flushHeaders();
    $this->app['auth']->forgetGuards();

    try {
        $this->travel(31)->days();

        $this->artisan('users:purge-deleted')->assertSuccessful();

        expect(
            DB::table('slug_tombstones')
                ->where('domain_id', $domain->id)
                ->where('slug', 'warm001')
                ->exists()
        )->toBeTrue();

        // The warmed 302 has a 24 h lifetime; travelling the clock does not
        // expire a real Valkey TTL, so a 410 here proves the purge evicted it.
        $this->get('https://iurl.fr/warm001')->assertStatus(410);
    } finally {
        $this->travelBack();
    }
});
