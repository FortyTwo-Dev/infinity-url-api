<?php

use App\Enums\LinkStatus;
use App\Models\Domain;
use App\Models\Link;

it('serves a bodyless and cookieless redirect', function () {
    $domain = Domain::factory()->forHost('iurl.fr')->create();
    Link::factory()->forDomain($domain)->create([
        'slug' => 'private1',
        'destination' => 'https://example.com/private',
    ]);

    $response = $this->get('http://iurl.fr/private1');
    $body = (string) $response->getContent();

    $response->assertStatus(302);
    expect($response->headers->getCookies())->toBe([]);
    expect($body)->toBe('');
    expect(stripos($body, '<script'))->toBeFalse();
    expect($body)->not->toContain('<');
});

it('sets no cookie on the error pages', function () {
    $domain = Domain::factory()->forHost('iurl.fr')->create();

    Link::factory()->forDomain($domain)->create([
        'slug' => 'expired-private',
        'status' => LinkStatus::Active,
        'expires_at' => now()->subMinute(),
    ]);

    Link::factory()->forDomain($domain)->create([
        'slug' => 'blocked-private',
        'status' => LinkStatus::Blocked,
    ]);

    $responses = [
        404 => $this->get('http://iurl.fr/unknown-private'),
        410 => $this->get('http://iurl.fr/expired-private'),
        403 => $this->get('http://iurl.fr/blocked-private'),
    ];

    foreach ($responses as $status => $response) {
        $response->assertStatus($status);
        expect($response->headers->getCookies())->toBe([]);
        expect(stripos((string) $response->getContent(), '<script'))->toBeFalse();
    }
});
