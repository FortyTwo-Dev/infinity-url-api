<?php

use App\Models\Link;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

it('deletes the account, soft deletes it and revokes every token', function () {
    [, $user] = $this->account('delete@example.com');

    $token = $this->deleteAccount($user);

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
    $domain = $this->linkDomain();

    [$workspace, $user] = $this->account('restore@example.com', ['name' => 'My workspace']);
    $this->linkOn($workspace, $domain, 'keepme1');

    $this->deleteAccount($user);

    $this->flushHeaders();

    expect(User::find($user->id))->toBeNull();

    $restored = $this->login('restore@example.com');
    expect($restored->json('user.id'))->toBe($user->public_id);

    $again = User::find($user->id);
    expect($again)->not->toBeNull();
    expect($again->trashed())->toBeFalse();

    expect(Link::query()->where('workspace_id', $workspace->id)->count())->toBe(1);
});

it('refuses the login after the grace period and purges the account with slug tombstones', function () {
    $domain = $this->linkDomain();

    [$workspace, $user] = $this->account('purged@example.com');
    $this->linkOn($workspace, $domain, 'purge001');

    $this->deleteAccount($user);

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
    $domain = $this->linkDomain();

    [$workspace, $user] = $this->account('warmcache@example.com');
    $this->linkOn($workspace, $domain, 'warm001');

    $this->get('https://iurl.fr/warm001')->assertStatus(302);
    expect(Cache::has('link:iurl.fr:warm001'))->toBeTrue();

    $this->deleteAccount($user);

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
