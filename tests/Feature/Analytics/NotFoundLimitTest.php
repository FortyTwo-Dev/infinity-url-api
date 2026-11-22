<?php

use App\Models\Domain;
use App\Models\Link;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Tests\Support\AnalyticsHelpers;

uses(AnalyticsHelpers::class);

const NOT_FOUND_LIMIT_IP = '198.51.100.7';

it('answers 429 on the sixty first unknown slug from one address', function () {
    Queue::fake();
    Domain::factory()->forHost('iurl.fr')->create();

    $this->withServerVariables(['REMOTE_ADDR' => NOT_FOUND_LIMIT_IP]);

    for ($attempt = 1; $attempt <= 60; $attempt++) {
        $this->get('https://iurl.fr/unknown'.$attempt)->assertNotFound();
    }

    $limited = $this->get('https://iurl.fr/unknown-61');

    $limited->assertStatus(429);
    expect($limited->headers->get('Retry-After'))->not->toBeNull();
    expect((string) $limited->getContent())->toBe('');
    expect($limited->headers->getCookies())->toBe([]);
});

it('answers 404 to another address while one address is limited', function () {
    Queue::fake();
    Domain::factory()->forHost('iurl.fr')->create();

    $this->withServerVariables(['REMOTE_ADDR' => NOT_FOUND_LIMIT_IP]);

    for ($attempt = 1; $attempt <= 60; $attempt++) {
        $this->get('https://iurl.fr/limited'.$attempt)->assertNotFound();
    }

    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.99']);

    $this->get('https://iurl.fr/limited-fresh')->assertNotFound();
});

it('does not spend the unknown slug budget on successful redirects', function () {
    Queue::fake();

    $domain = Domain::factory()->forHost('iurl.fr')->create();
    Link::factory()->forDomain($domain)->create([
        'slug' => 'budget-ok',
        'destination' => 'https://example.com/budget',
    ]);

    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.23']);

    for ($attempt = 1; $attempt <= 70; $attempt++) {
        $this->get('https://iurl.fr/budget-ok')->assertStatus(302);
    }

    $this->get('https://iurl.fr/budget-missing')->assertNotFound();
});

it('still answers 404 when the cache is unavailable', function () {
    Queue::fake();

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

    $this->app->instance(Repository::class, Cache::store('dead'));

    Domain::factory()->forHost('iurl.fr')->create();

    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.55']);

    $this->get('https://iurl.fr/no-cache-missing')->assertNotFound();
});

it('fails open on the unknown slug limiter when the salt is missing', function () {
    $logFile = (string) tempnam(sys_get_temp_dir(), 'iurl-saltless-log-');

    config([
        'logging.channels.saltless_probe' => [
            'driver' => 'single',
            'path' => $logFile,
            'level' => 'debug',
        ],
        'logging.default' => 'saltless_probe',
    ]);

    Log::forgetChannel('saltless_probe');

    Domain::factory()->forHost('iurl.fr')->create();

    $this->deleteSaltFile();

    $this->withServerVariables(['REMOTE_ADDR' => NOT_FOUND_LIMIT_IP]);

    try {
        for ($attempt = 1; $attempt <= 61; $attempt++) {
            $this->get('https://iurl.fr/saltless'.$attempt)->assertNotFound();
        }
    } finally {
        @unlink($logFile);
    }
});
