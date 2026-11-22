<?php

use App\Models\Domain;
use App\Models\Link;
use Database\Seeders\LinkDomainSeeder;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake();
});

it('redirects the report entry point to the dashboard form', function () {
    $response = $this->get('https://iurl.fr/report/abc12');

    $response->assertStatus(302)
        ->assertHeader('Location', 'https://url.infinityproject.fr/report?slug=abc12')
        ->assertHeader('X-Robots-Tag', 'noindex');

    expect($response->headers->getCookies())->toBe([])
        ->and((string) $response->getContent())->toBe('');
});

it('never counts the report redirect as a click', function () {
    $this->seed(LinkDomainSeeder::class);
    $domain = Domain::query()->where('hostname', 'iurl.fr')->firstOrFail();
    Link::factory()->forDomain($domain)->create(['slug' => 'abc12']);

    $this->get('https://iurl.fr/report/abc12')->assertStatus(302);

    Queue::assertNothingPushed();
});

it('keeps the link host closed everywhere else', function () {
    $this->get('https://iurl.fr/report/abc12/extra')->assertNotFound();
    $this->get('https://iurl.fr/docs/report/abc12')->assertNotFound();
    $this->post('https://iurl.fr/report/abc12')->assertNotFound();
});

it('serves a report link in the footer of a terminal page', function () {
    $response = $this->get('https://iurl.fr/unknown1');

    $response->assertStatus(404);
    expect((string) $response->getContent())
        ->toContain('https://url.infinityproject.fr/report?slug=unknown1');
});

it('serves a report link on the blocked page', function () {
    $this->seed(LinkDomainSeeder::class);
    $domain = Domain::query()->where('hostname', 'iurl.fr')->firstOrFail();
    Link::factory()->forDomain($domain)->blocked()->create(['slug' => 'blocked1']);

    $response = $this->get('https://iurl.fr/blocked1');

    $response->assertStatus(403);
    expect((string) $response->getContent())
        ->toContain('https://url.infinityproject.fr/report?slug=blocked1');
});

it('keeps the terminal pages static', function () {
    $body = (string) $this->get('https://iurl.fr/unknown1')->getContent();

    expect(stripos($body, '<script'))->toBeFalse()
        ->and(stripos($body, 'src='))->toBeFalse()
        ->and(stripos($body, 'url(http'))->toBeFalse();
});
