<?php

it('does not serve the account endpoint on the link host', function () {
    $response = $this->get('https://iurl.fr/v1/me');

    $response->assertNotFound();

    expect($response->headers->getCookies())->toBe([]);
});

it('does not serve link creation on the link host', function () {
    $response = $this->postJson('https://iurl.fr/v1/links', [
        'destination' => 'https://example.com/link-host',
    ]);

    $response->assertNotFound();

    expect($response->headers->getCookies())->toBe([]);
});

it('answers unauthorized in problem json on the api host without a token', function () {
    $response = $this->getJson('https://api.url.infinityproject.fr/v1/me');

    $response->assertStatus(401)
        ->assertJsonPath('code', 'unauthorized');

    expect($response->headers->get('Content-Type'))->toContain('application/problem+json');
});
