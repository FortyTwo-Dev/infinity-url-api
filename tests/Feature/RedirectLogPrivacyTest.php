<?php

use App\Models\Domain;
use App\Models\Link;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

it('never logs the visitor user agent, referer or ip address', function () {
    $domain = Domain::factory()->forHost('iurl.fr')->create();
    Link::factory()->forDomain($domain)->create([
        'slug' => 'logprobe1',
        'destination' => 'https://example.com/logprobe',
    ]);

    $logFile = (string) tempnam(sys_get_temp_dir(), 'iurl-privacy-log-');

    config([
        'logging.channels.privacy_probe' => [
            'driver' => 'single',
            'path' => $logFile,
            'level' => 'debug',
        ],
        'logging.default' => 'privacy_probe',
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

    Log::forgetChannel('privacy_probe');
    $this->app->instance(Repository::class, Cache::store('dead'));

    try {
        $response = $this->withHeaders([
            'User-Agent' => 'PrivacyProbeUA/1.0',
            'Referer' => 'https://privacy-probe.example/secret',
        ])->withServerVariables([
            'REMOTE_ADDR' => '203.0.113.42',
        ])->get('https://iurl.fr/logprobe1');

        $response->assertStatus(302);

        $log = (string) file_get_contents($logFile);
    } finally {
        @unlink($logFile);
    }

    expect($log)->not->toBe('');
    expect($log)->not->toContain('PrivacyProbeUA/1.0');
    expect($log)->not->toContain('https://privacy-probe.example/secret');
    expect($log)->not->toContain('203.0.113.42');
});
