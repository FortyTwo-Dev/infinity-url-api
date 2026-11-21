<?php

use App\Enums\DomainStatus;
use App\Enums\LinkStatus;
use App\Models\Domain;
use App\Models\Link;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

it('redirects an active link with the privacy headers and no body', function () {
    $domain = Domain::factory()->forHost('iurl.fr')->create();
    Link::factory()->forDomain($domain)->create([
        'slug' => 'active1',
        'destination' => 'https://example.com/page',
    ]);

    $response = $this->get('https://iurl.fr/active1');

    $response->assertStatus(302);
    $response->assertHeader('Location', 'https://example.com/page');
    $response->assertHeader('Cache-Control', 'no-store');
    $response->assertHeader('Referrer-Policy', 'no-referrer');
    $response->assertHeader('X-Robots-Tag', 'noindex');
    expect((string) $response->getContent())->toBe('');
});

it('answers 404 for an unknown slug', function () {
    Domain::factory()->forHost('iurl.fr')->create();

    $response = $this->get('https://iurl.fr/unknown1');

    $response->assertNotFound();
    $response->assertSee('Ce lien n\'existe pas.', false);
});

it('answers 410 for an expired link', function () {
    $domain = Domain::factory()->forHost('iurl.fr')->create();
    Link::factory()->forDomain($domain)->create([
        'slug' => 'expired1',
        'status' => LinkStatus::Active,
        'expires_at' => now()->subMinute(),
    ]);

    $response = $this->get('https://iurl.fr/expired1');

    $response->assertStatus(410);
    $response->assertSee('Ce lien a expiré.', false);
});

it('answers 410 for a disabled link', function () {
    $domain = Domain::factory()->forHost('iurl.fr')->create();
    Link::factory()->forDomain($domain)->create([
        'slug' => 'disabled1',
        'status' => LinkStatus::Disabled,
    ]);

    $response = $this->get('https://iurl.fr/disabled1');

    $response->assertStatus(410);
    $response->assertSee('Ce lien a expiré.', false);
});

it('answers 403 for a blocked link', function () {
    $domain = Domain::factory()->forHost('iurl.fr')->create();
    Link::factory()->forDomain($domain)->create([
        'slug' => 'blocked1',
        'status' => LinkStatus::Blocked,
    ]);

    $response = $this->get('https://iurl.fr/blocked1');

    $response->assertStatus(403);
    $response->assertSee('Ce lien a été bloqué pour abus.', false);
    $response->assertSee('abuse@iurl.fr', false);
});

it('answers 410 for a soft-deleted link', function () {
    $domain = Domain::factory()->forHost('iurl.fr')->create();
    $link = Link::factory()->forDomain($domain)->create(['slug' => 'deleted1']);

    $link->delete();

    $response = $this->get('https://iurl.fr/deleted1');

    $response->assertStatus(410);
    $response->assertSee('Ce lien a été supprimé définitivement.', false);
});

it('answers 410 for a slug in the tombstone table', function () {
    $domain = Domain::factory()->forHost('iurl.fr')->create();

    DB::table('slug_tombstones')->insert([
        'domain_id' => $domain->id,
        'slug' => 'erased1',
        'erased_at' => now(),
    ]);

    $response = $this->get('https://iurl.fr/erased1');

    $response->assertStatus(410);
    $response->assertSee('Ce lien a été supprimé définitivement.', false);
});

it('answers 404 for a link whose domain is pending', function () {
    $domain = Domain::factory()->forHost('iurl.fr')->create([
        'status' => DomainStatus::Pending,
        'verified_at' => null,
        'tls_status' => 'none',
        'tls_expires_at' => null,
    ]);
    Link::factory()->forDomain($domain)->create([
        'slug' => 'pending1',
        'destination' => 'https://example.com/pending',
    ]);

    $response = $this->get('https://iurl.fr/pending1');

    $response->assertNotFound();
    $response->assertSee('Ce lien n\'existe pas.', false);
});

it('treats an uppercase slug like its lowercase form', function () {
    $domain = Domain::factory()->forHost('iurl.fr')->create();
    Link::factory()->forDomain($domain)->create([
        'slug' => 'mixedcase1',
        'destination' => 'https://example.com/mixed',
    ]);

    $response = $this->get('https://iurl.fr/MIXEDCASE1');

    $response->assertStatus(302);
    $response->assertHeader('Location', 'https://example.com/mixed');
});

it('answers HEAD without a body', function () {
    $domain = Domain::factory()->forHost('iurl.fr')->create();
    Link::factory()->forDomain($domain)->create([
        'slug' => 'head1',
        'destination' => 'https://example.com/head',
    ]);

    $response = $this->head('https://iurl.fr/head1');

    $response->assertStatus(302);
    $response->assertHeader('Location', 'https://example.com/head');
    expect((string) $response->getContent())->toBe('');
});

it('answers 410 when expires_at is exactly now', function () {
    $domain = Domain::factory()->forHost('iurl.fr')->create();
    Link::factory()->forDomain($domain)->create([
        'slug' => 'expiresnow1',
        'status' => LinkStatus::Active,
        'expires_at' => now(),
    ]);

    $response = $this->get('https://iurl.fr/expiresnow1');

    $response->assertStatus(410);
    $response->assertSee('Ce lien a expiré.', false);
});

it('enforces the slug length boundaries', function () {
    $domain = Domain::factory()->forHost('iurl.fr')->create();

    // Length 1 and 65 are rejected by the links_slug_format constraint, so no
    // link can exist for them; they must still answer 404.
    $this->get('https://iurl.fr/a')->assertNotFound();
    $this->get('https://iurl.fr/'.str_repeat('b', 65))->assertNotFound();

    Link::factory()->forDomain($domain)->create([
        'slug' => 'ab',
        'destination' => 'https://example.com/two',
    ]);
    Link::factory()->forDomain($domain)->create([
        'slug' => str_repeat('c', 64),
        'destination' => 'https://example.com/sixty-four',
    ]);

    $this->get('https://iurl.fr/ab')
        ->assertStatus(302)
        ->assertHeader('Location', 'https://example.com/two');

    $this->get('https://iurl.fr/'.str_repeat('c', 64))
        ->assertStatus(302)
        ->assertHeader('Location', 'https://example.com/sixty-four');
});

it('does not cache a redirect whose ttl rounds down to zero', function () {
    $this->travelTo(now());

    try {
        $domain = Domain::factory()->forHost('iurl.fr')->create();
        $link = Link::factory()->forDomain($domain)->create([
            'slug' => 'zerottl1',
            'status' => LinkStatus::Active,
            'expires_at' => now()->addMilliseconds(500),
        ]);

        // Eloquent's default date format drops sub-second precision on write.
        // Write the exact 500 ms value through the query builder instead.
        DB::table('links')->where('id', $link->id)->update([
            'expires_at' => now()->addMilliseconds(500)->format('Y-m-d H:i:s.uP'),
        ]);

        $response = $this->get('https://iurl.fr/zerottl1');

        $response->assertStatus(302);
        expect(Cache::has('link:iurl.fr:zerottl1'))->toBeFalse();
    } finally {
        $this->travelBack();
    }
});

it('never queues analytics or writes to the database during a redirect', function () {
    Queue::fake();

    $domain = Domain::factory()->forHost('iurl.fr')->create();
    Link::factory()->forDomain($domain)->create([
        'slug' => 'readonly1',
        'destination' => 'https://example.com/readonly',
    ]);

    $writes = [];

    DB::listen(function ($query) use (&$writes): void {
        if (preg_match('/^\s*(insert|update|delete|truncate)\b/i', $query->sql) === 1) {
            $writes[] = $query->sql;
        }
    });

    $this->get('https://iurl.fr/readonly1')->assertStatus(302);

    Queue::assertNothingPushed();
    expect($writes)->toBe([]);
});
