<?php

use App\Enums\LinkStatus;
use App\Models\Domain;
use App\Models\Link;
use Illuminate\Testing\TestResponse;

function assertIurlErrorPageShape(TestResponse $response, string $text): void
{
    $body = (string) $response->getContent();

    $response->assertHeader('X-Robots-Tag', 'noindex');

    expect($body)->toContain($text);
    expect($body)->toContain('lang="fr"');
    expect($body)->toContain('<title>');
    expect(strlen($body))->toBeLessThan(2048);
}

it('serves a 404 page that points to the home site', function () {
    Domain::factory()->forHost('iurl.fr')->create();

    $response = $this->get('http://iurl.fr/not-found-page');

    $response->assertStatus(404);
    assertIurlErrorPageShape($response, 'Ce lien n\'existe pas.');
    expect((string) $response->getContent())->toContain('https://infinityproject.fr');
});

it('serves a 410 page for a deleted link', function () {
    $domain = Domain::factory()->forHost('iurl.fr')->create();
    $link = Link::factory()->forDomain($domain)->create(['slug' => 'deleted-page']);

    $link->delete();

    $response = $this->get('http://iurl.fr/deleted-page');

    $response->assertStatus(410);
    assertIurlErrorPageShape($response, 'Ce lien a été supprimé définitivement.');
});

it('serves a 410 page for an expired link', function () {
    $domain = Domain::factory()->forHost('iurl.fr')->create();
    Link::factory()->forDomain($domain)->create([
        'slug' => 'expired-page',
        'status' => LinkStatus::Active,
        'expires_at' => now()->subMinute(),
    ]);

    $response = $this->get('http://iurl.fr/expired-page');

    $response->assertStatus(410);
    assertIurlErrorPageShape($response, 'Ce lien a expiré.');
});

it('serves a 403 page for a blocked link', function () {
    $domain = Domain::factory()->forHost('iurl.fr')->create();
    Link::factory()->forDomain($domain)->create([
        'slug' => 'blocked-page',
        'status' => LinkStatus::Blocked,
    ]);

    $response = $this->get('http://iurl.fr/blocked-page');

    $response->assertStatus(403);
    assertIurlErrorPageShape($response, 'Ce lien a été bloqué pour abus.');
    expect((string) $response->getContent())->toContain('abuse@iurl.fr');
});
