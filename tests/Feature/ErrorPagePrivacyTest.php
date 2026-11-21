<?php

use App\Enums\LinkStatus;
use App\Models\Domain;
use App\Models\Link;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    Http::preventStrayRequests();
    Http::fake();
});

function assertIurlErrorPageIsStatic(TestResponse $response): void
{
    $body = (string) $response->getContent();

    expect($response->headers->getCookies())->toBe([]);
    expect(stripos($body, '<script'))->toBeFalse();
    expect(stripos($body, 'src='))->toBeFalse();
    expect(stripos($body, '<link rel="stylesheet"'))->toBeFalse();
    expect(preg_match('/<link\b[^>]*\brel=["\']?stylesheet/i', $body))->toBe(0);
    expect(stripos($body, '@import'))->toBeFalse();
    expect(stripos($body, 'url(http'))->toBeFalse();

    Http::assertNothingSent();
}

it('serves a static 404 page', function () {
    Domain::factory()->forHost('iurl.fr')->create();

    $response = $this->get('http://iurl.fr/unknown-static');

    $response->assertStatus(404);
    assertIurlErrorPageIsStatic($response);
});

it('serves a static 410 page for a deleted link', function () {
    $domain = Domain::factory()->forHost('iurl.fr')->create();
    $link = Link::factory()->forDomain($domain)->create(['slug' => 'deleted-static']);

    $link->delete();

    $response = $this->get('http://iurl.fr/deleted-static');

    $response->assertStatus(410);
    assertIurlErrorPageIsStatic($response);
});

it('serves a static 410 page for an expired link', function () {
    $domain = Domain::factory()->forHost('iurl.fr')->create();
    Link::factory()->forDomain($domain)->create([
        'slug' => 'expired-static',
        'status' => LinkStatus::Active,
        'expires_at' => now()->subMinute(),
    ]);

    $response = $this->get('http://iurl.fr/expired-static');

    $response->assertStatus(410);
    assertIurlErrorPageIsStatic($response);
});

it('serves a static 403 page for a blocked link', function () {
    $domain = Domain::factory()->forHost('iurl.fr')->create();
    Link::factory()->forDomain($domain)->create([
        'slug' => 'blocked-static',
        'status' => LinkStatus::Blocked,
    ]);

    $response = $this->get('http://iurl.fr/blocked-static');

    $response->assertStatus(403);
    assertIurlErrorPageIsStatic($response);
});
