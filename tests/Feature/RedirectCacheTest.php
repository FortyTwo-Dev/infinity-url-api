<?php

use App\Enums\LinkStatus;
use App\Models\Domain;
use App\Models\Link;
use App\Services\RedirectCache;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

function redirectCacheTtl(string $key): int
{
    $store = Cache::store();

    return (int) $store->getStore()->connection()->ttl($store->getStore()->getPrefix().$key);
}

it('serves a warm redirect without reading the links table', function () {
    $domain = Domain::factory()->forHost('iurl.fr')->create();
    Link::factory()->forDomain($domain)->create([
        'slug' => 'warm1',
        'destination' => 'https://example.com/warm',
    ]);

    $this->get('http://iurl.fr/warm1')->assertStatus(302);

    $reads = 0;

    DB::listen(function ($query) use (&$reads): void {
        if (str_contains($query->sql, 'from "links"')) {
            $reads++;
        }
    });

    $this->get('http://iurl.fr/warm1')
        ->assertStatus(302)
        ->assertHeader('Location', 'https://example.com/warm');

    expect($reads)->toBe(0);
});

it('serves a negative cache hit without reading the links table', function () {
    Domain::factory()->forHost('iurl.fr')->create();

    $this->get('http://iurl.fr/missing1')->assertNotFound();

    expect(Cache::has('link:iurl.fr:missing1'))->toBeTrue();

    $reads = 0;

    DB::listen(function ($query) use (&$reads): void {
        if (str_contains($query->sql, 'from "links"')) {
            $reads++;
        }
    });

    $this->get('http://iurl.fr/missing1')->assertNotFound();

    expect($reads)->toBe(0);
});

it('bounds the redirect cache ttl by the link expiration', function () {
    $domain = Domain::factory()->forHost('iurl.fr')->create();
    Link::factory()->forDomain($domain)->create([
        'slug' => 'shortttl1',
        'destination' => 'https://example.com/short',
        'expires_at' => now()->addSeconds(2),
    ]);

    $this->get('http://iurl.fr/shortttl1')->assertStatus(302);

    $key = 'link:iurl.fr:shortttl1';
    $store = Cache::store();
    $ttl = $store->getStore()->connection()->ttl($store->getStore()->getPrefix().$key);

    expect(Cache::has($key))->toBeTrue();
    expect($ttl)->toBeGreaterThan(0);
    expect($ttl)->toBeLessThanOrEqual(2);
});

it('never caches a redirect for an already expired link', function () {
    $domain = Domain::factory()->forHost('iurl.fr')->create();
    Link::factory()->forDomain($domain)->create([
        'slug' => 'expiredcache1',
        'status' => LinkStatus::Active,
        'expires_at' => now()->subMinute(),
    ]);

    $this->get('http://iurl.fr/expiredcache1')->assertStatus(410);

    $cached = Cache::get('link:iurl.fr:expiredcache1');

    expect((int) data_get($cached, 'status'))->toBe(410);
});

it('forgets a redirect cache entry explicitly', function () {
    $domain = Domain::factory()->forHost('iurl.fr')->create();
    $slug = 'forget1';

    Link::factory()->forDomain($domain)->create([
        'slug' => $slug,
        'destination' => 'https://example.com/forget',
    ]);

    $this->get("http://iurl.fr/{$slug}")->assertStatus(302);

    $reads = 0;

    DB::listen(function ($query) use (&$reads): void {
        if (str_contains($query->sql, 'from "links"')) {
            $reads++;
        }
    });

    $this->get("http://iurl.fr/{$slug}")
        ->assertStatus(302)
        ->assertHeader('Location', 'https://example.com/forget');

    expect($reads)->toBe(0);

    app(RedirectCache::class)->forget('iurl.fr', $slug);

    $reads = 0;

    $this->get("http://iurl.fr/{$slug}")
        ->assertStatus(302)
        ->assertHeader('Location', 'https://example.com/forget');

    expect($reads)->toBe(1);
});

it('caches a negative hit for at most sixty seconds', function () {
    Domain::factory()->forHost('iurl.fr')->create();

    $slug = 'ttlmissing1';

    $this->get("http://iurl.fr/{$slug}")->assertNotFound();

    $ttl = redirectCacheTtl("link:iurl.fr:{$slug}");

    expect($ttl)->toBeGreaterThan(0);
    expect($ttl)->toBeLessThanOrEqual(60);
});

it('caches transient error states for at most sixty seconds', function () {
    $domain = Domain::factory()->forHost('iurl.fr')->create();

    Link::factory()->forDomain($domain)->create([
        'slug' => 'ttlblocked1',
        'status' => LinkStatus::Blocked,
    ]);
    Link::factory()->forDomain($domain)->create([
        'slug' => 'ttlexpired1',
        'status' => LinkStatus::Active,
        'expires_at' => now()->subMinute(),
    ]);

    $this->get('http://iurl.fr/ttlblocked1')->assertStatus(403);
    $this->get('http://iurl.fr/ttlexpired1')->assertStatus(410);

    $blockedTtl = redirectCacheTtl('link:iurl.fr:ttlblocked1');
    $expiredTtl = redirectCacheTtl('link:iurl.fr:ttlexpired1');

    expect($blockedTtl)->toBeGreaterThan(0);
    expect($blockedTtl)->toBeLessThanOrEqual(60);
    expect($expiredTtl)->toBeGreaterThan(0);
    expect($expiredTtl)->toBeLessThanOrEqual(60);
});

it('caches deleted and tombstoned slugs for a day', function () {
    $domain = Domain::factory()->forHost('iurl.fr')->create();

    $link = Link::factory()->forDomain($domain)->create(['slug' => 'ttldeleted1']);
    $link->delete();

    DB::table('slug_tombstones')->insert([
        'domain_id' => $domain->id,
        'slug' => 'ttlerased1',
        'erased_at' => now(),
    ]);

    $this->get('http://iurl.fr/ttldeleted1')->assertStatus(410);
    $this->get('http://iurl.fr/ttlerased1')->assertStatus(410);

    $deletedTtl = redirectCacheTtl('link:iurl.fr:ttldeleted1');
    $erasedTtl = redirectCacheTtl('link:iurl.fr:ttlerased1');

    expect($deletedTtl)->toBeGreaterThan(60);
    expect($deletedTtl)->toBeLessThanOrEqual(86_400);
    expect($erasedTtl)->toBeGreaterThan(60);
    expect($erasedTtl)->toBeLessThanOrEqual(86_400);
});

dataset('malformed redirect cache values', [
    'empty value' => [[]],
    'null status' => [['status' => null]],
    'string status' => [['status' => '404']],
    'missing location' => [['status' => 302]],
    'server error status' => [['status' => 500]],
]);

it('replaces a malformed cache value from postgres', function (array $cached): void {
    $domain = Domain::factory()->forHost('iurl.fr')->create();
    Link::factory()->forDomain($domain)->create([
        'slug' => 'malformed1',
        'destination' => 'https://example.com/malformed',
    ]);

    $key = 'link:iurl.fr:malformed1';
    Cache::put($key, $cached, 60);

    $this->get('http://iurl.fr/malformed1')
        ->assertStatus(302)
        ->assertHeader('Location', 'https://example.com/malformed');

    $rewritten = Cache::get($key);

    expect(data_get($rewritten, 'status'))->toBe(302);
    expect(data_get($rewritten, 'location'))->toBe('https://example.com/malformed');
})->with('malformed redirect cache values');

it('invalidates the cache when a link destination is updated', function () {
    $domain = Domain::factory()->forHost('iurl.fr')->create();
    $link = Link::factory()->forDomain($domain)->create([
        'slug' => 'mutdest1',
        'destination' => 'https://example.com/old',
    ]);

    $this->get('http://iurl.fr/mutdest1')
        ->assertStatus(302)
        ->assertHeader('Location', 'https://example.com/old');

    $reads = 0;

    DB::listen(function ($query) use (&$reads): void {
        if (str_contains($query->sql, 'from "links"')) {
            $reads++;
        }
    });

    $link->update(['destination' => 'https://example.com/new']);

    $this->get('http://iurl.fr/mutdest1')
        ->assertStatus(302)
        ->assertHeader('Location', 'https://example.com/new');

    expect($reads)->toBeGreaterThanOrEqual(1);
});

it('invalidates the cache when a link is blocked', function () {
    $domain = Domain::factory()->forHost('iurl.fr')->create();
    $link = Link::factory()->forDomain($domain)->create(['slug' => 'mutblocked1']);

    $this->get('http://iurl.fr/mutblocked1')->assertStatus(302);

    $link->update(['status' => LinkStatus::Blocked]);

    $response = $this->get('http://iurl.fr/mutblocked1');

    $response->assertStatus(403);
    $response->assertSee('Ce lien a été bloqué pour abus.', false);
});

it('invalidates the cache when a link is soft deleted', function () {
    $domain = Domain::factory()->forHost('iurl.fr')->create();
    $link = Link::factory()->forDomain($domain)->create(['slug' => 'mutdeleted1']);

    $this->get('http://iurl.fr/mutdeleted1')->assertStatus(302);

    $link->delete();

    $response = $this->get('http://iurl.fr/mutdeleted1');

    $response->assertStatus(410);
    $response->assertSee('Ce lien a été supprimé définitivement.', false);
});

it('invalidates both slugs when a link is renamed', function () {
    $domain = Domain::factory()->forHost('iurl.fr')->create();
    $link = Link::factory()->forDomain($domain)->create([
        'slug' => 'oldname1',
        'destination' => 'https://example.com/renamed',
    ]);

    Cache::put('link:iurl.fr:newname1', ['status' => 404], 60);

    $this->get('http://iurl.fr/oldname1')->assertStatus(302);

    $link->update(['slug' => 'newname1']);

    $this->get('http://iurl.fr/oldname1')
        ->assertNotFound()
        ->assertSee('Ce lien n\'existe pas.', false);

    $this->get('http://iurl.fr/newname1')
        ->assertStatus(302)
        ->assertHeader('Location', 'https://example.com/renamed');
});

it('invalidates the negative cache when a link is created', function () {
    $domain = Domain::factory()->forHost('iurl.fr')->create();
    $slug = 'created1';

    $this->get("http://iurl.fr/{$slug}")->assertNotFound();

    Link::factory()->forDomain($domain)->create([
        'slug' => $slug,
        'destination' => 'https://example.com/created',
    ]);

    $this->get("http://iurl.fr/{$slug}")
        ->assertStatus(302)
        ->assertHeader('Location', 'https://example.com/created');
});

it('falls back to postgres when valkey is unavailable', function () {
    config([
        'database.redis.dead' => [
            'host' => '127.0.0.1',
            'port' => 6399,
            'database' => 0,
            'timeout' => 0.2,
            'read_timeout' => 0.2,
            'max_retries' => 0,
        ],
        'cache.stores.dead' => [
            'driver' => 'redis',
            'connection' => 'dead',
        ],
        'cache.default' => 'dead',
    ]);

    $deadRepository = Cache::store('dead');
    $this->app->instance(Repository::class, $deadRepository);

    $domain = Domain::factory()->forHost('iurl.fr')->create();
    Link::factory()->forDomain($domain)->create([
        'slug' => 'fallback1',
        'destination' => 'https://example.com/fallback',
    ]);

    $reads = 0;

    DB::listen(function ($query) use (&$reads): void {
        if (str_contains($query->sql, 'from "links"')) {
            $reads++;
        }
    });

    $this->get('http://iurl.fr/fallback1')
        ->assertStatus(302)
        ->assertHeader('Location', 'https://example.com/fallback');

    expect($reads)->toBeGreaterThanOrEqual(1);
});
