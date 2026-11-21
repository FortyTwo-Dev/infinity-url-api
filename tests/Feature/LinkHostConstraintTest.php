<?php

use App\Models\Domain;
use App\Models\Link;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    Gate::define('viewApiDocs', fn ($user = null): bool => true);

    // The Boost vendor route is registered only in local or debug environments.
    // Stub it so the host constraint is tested in the testing environment too.
    if (! Route::has('boost.browser-logs')) {
        Route::post('/_boost/browser-logs', fn () => response()->noContent())
            ->name('boost.browser-logs');
    }
});

it('rejects every non-link path on the link host', function () {
    $this->get('https://iurl.fr/docs/api')->assertNotFound();
    $this->get('https://iurl.fr/horizon')->assertNotFound();
    $this->get('https://iurl.fr/_scramble/dev-tools/devtools.js')->assertNotFound();
    $this->get('https://iurl.fr/up')->assertNotFound();
    $this->get('https://iurl.fr/api/user')->assertNotFound();
    $this->get('https://iurl.fr/')->assertNotFound();
    $this->post('https://iurl.fr/_boost/browser-logs', [])->assertNotFound();
});

it('serves the application routes on the api host', function () {
    $this->get('https://api.url.infinityproject.fr/docs/api')->assertOk();
    $this->get('https://api.url.infinityproject.fr/up')->assertOk();

    expect($this->get('https://api.url.infinityproject.fr/horizon')->getStatusCode())->not->toBe(404);
    expect($this->get('https://api.url.infinityproject.fr/_scramble/dev-tools/devtools.js')->getStatusCode())->not->toBe(404);
    expect($this->post('https://api.url.infinityproject.fr/_boost/browser-logs', [])->getStatusCode())->not->toBe(404);

    $this->get('https://api.url.infinityproject.fr/api/user')->assertNotFound();
});

it('does not serve redirects on the api host', function () {
    $this->get('https://api.url.infinityproject.fr/un-slug')->assertNotFound();
});

it('accepts only GET and HEAD on the link host', function () {
    $domain = Domain::factory()->forHost('iurl.fr')->create();
    Link::factory()->forDomain($domain)->create([
        'slug' => 'active2',
        'destination' => 'https://example.com/active',
    ]);

    $this->post('https://iurl.fr/active2')->assertNotFound();
    $this->head('https://iurl.fr/active2')->assertStatus(302);
});

it('rejects a single trailing slash on a known slug', function () {
    $domain = Domain::factory()->forHost('iurl.fr')->create();
    Link::factory()->forDomain($domain)->create([
        'slug' => 'trailing1',
        'destination' => 'https://example.com/trailing',
    ]);

    $kernel = $this->app->make(Kernel::class);
    $request = Request::create('https://iurl.fr/trailing1/');
    $response = $kernel->handle($request);

    expect($response->getStatusCode())->toBe(404);
});

it('rejects a double trailing slash on a known slug', function () {
    $domain = Domain::factory()->forHost('iurl.fr')->create();
    Link::factory()->forDomain($domain)->create([
        'slug' => 'trailing2',
        'destination' => 'https://example.com/trailing',
    ]);

    $kernel = $this->app->make(Kernel::class);
    $request = Request::create('https://iurl.fr/trailing2//');
    $response = $kernel->handle($request);

    expect($response->getStatusCode())->toBe(404);
});

it('never sets a cookie on the link host', function () {
    $domain = Domain::factory()->forHost('iurl.fr')->create();
    Link::factory()->forDomain($domain)->create([
        'slug' => 'active3',
        'destination' => 'https://example.com/active',
    ]);

    $paths = ['/', '/active3', '/un-slug', '/docs/api', '/horizon', '/api/user', '/up'];

    foreach ($paths as $path) {
        $response = $this->get('https://iurl.fr'.$path);

        expect($response->headers->getCookies())->toBe([]);
    }

    expect($this->post('https://iurl.fr/_boost/browser-logs', [])->headers->getCookies())->toBe([]);
});
