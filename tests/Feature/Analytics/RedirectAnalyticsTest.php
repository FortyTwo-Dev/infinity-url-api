<?php

use App\Enums\DeviceClass;
use App\Enums\LinkStatus;
use App\Jobs\RecordClick;
use App\Models\Domain;
use App\Models\Link;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Tests\Support\AnalyticsHelpers;

uses(AnalyticsHelpers::class);

const ANALYTICS_IPHONE_UA = 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_1 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.1 Mobile/15E148 Safari/604.1';

const ANALYTICS_GOOGLEBOT_UA = 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)';

it('queues exactly one analytics job for a counted redirect', function () {
    Queue::fake();
    $this->travelTo(CarbonImmutable::parse('2026-11-21 10:00:00', 'UTC'));

    $domain = Domain::factory()->forHost('iurl.fr')->create();
    $link = Link::factory()->forDomain($domain)->create([
        'slug' => 'counted1',
        'destination' => 'https://example.com/counted',
    ]);

    $this->withHeaders(['User-Agent' => ANALYTICS_IPHONE_UA])
        ->get('https://iurl.fr/counted1')
        ->assertStatus(302)
        ->assertHeader('Location', 'https://example.com/counted');

    Queue::assertPushedOn('analytics', RecordClick::class);
    Queue::assertPushed(RecordClick::class, 1);

    $job = Queue::pushed(RecordClick::class)->first();

    expect($job)->toBeInstanceOf(RecordClick::class)
        ->and($job->linkId)->toBe($link->id)
        ->and($job->date)->toBe('2026-11-21')
        ->and($job->device)->toBe(DeviceClass::Mobile)
        ->and($job->hashDaily)->toBeString()->not->toBe('')
        ->and($job->hashWeekly)->toBeString()->not->toBe('');
});

it('keeps the redirect bodyless, cookieless and private when it counts', function () {
    Queue::fake();

    $domain = Domain::factory()->forHost('iurl.fr')->create();
    Link::factory()->forDomain($domain)->create([
        'slug' => 'envelope1',
        'destination' => 'https://example.com/envelope',
    ]);

    $response = $this->withHeaders(['User-Agent' => ANALYTICS_IPHONE_UA])
        ->get('https://iurl.fr/envelope1');

    $response->assertStatus(302);
    $response->assertHeader('Cache-Control', 'no-store');
    $response->assertHeader('Referrer-Policy', 'no-referrer');
    $response->assertHeader('X-Robots-Tag', 'noindex');
    expect($response->headers->getCookies())->toBe([]);
    expect((string) $response->getContent())->toBe('');

    Queue::assertPushed(RecordClick::class);
});

it('does not count a HEAD request', function () {
    Queue::fake();

    $domain = Domain::factory()->forHost('iurl.fr')->create();
    Link::factory()->forDomain($domain)->create([
        'slug' => 'headcount1',
        'destination' => 'https://example.com/headcount',
    ]);

    $this->head('https://iurl.fr/headcount1')->assertStatus(302);

    Queue::assertNothingPushed();
});

it('does not count an identified bot', function () {
    Queue::fake();

    $domain = Domain::factory()->forHost('iurl.fr')->create();
    Link::factory()->forDomain($domain)->create([
        'slug' => 'botcount1',
        'destination' => 'https://example.com/botcount',
    ]);

    $this->withHeaders(['User-Agent' => ANALYTICS_GOOGLEBOT_UA])
        ->get('https://iurl.fr/botcount1')
        ->assertStatus(302);

    Queue::assertNothingPushed();
});

dataset('prefetch requests', [
    'sec-purpose' => [['Sec-Purpose' => 'prefetch']],
    'purpose' => [['Purpose' => 'prefetch']],
    'x-purpose' => [['X-Purpose' => 'preview']],
]);

it('does not count a prefetch or a preview', function (array $headers) {
    Queue::fake();

    $domain = Domain::factory()->forHost('iurl.fr')->create();
    Link::factory()->forDomain($domain)->create([
        'slug' => 'prefetch1',
        'destination' => 'https://example.com/prefetch',
    ]);

    $this->withHeaders(['User-Agent' => ANALYTICS_IPHONE_UA] + $headers)
        ->get('https://iurl.fr/prefetch1')
        ->assertStatus(302);

    Queue::assertNothingPushed();
})->with('prefetch requests');

it('never counts a terminal error page', function () {
    Queue::fake();

    $domain = Domain::factory()->forHost('iurl.fr')->create();

    Link::factory()->forDomain($domain)->create([
        'slug' => 'expiredcount1',
        'status' => LinkStatus::Active,
        'expires_at' => now()->subMinute(),
    ]);
    Link::factory()->forDomain($domain)->create([
        'slug' => 'blockedcount1',
        'status' => LinkStatus::Blocked,
    ]);

    $this->get('https://iurl.fr/unknowncount1')->assertNotFound();
    $this->get('https://iurl.fr/expiredcount1')->assertStatus(410);
    $this->get('https://iurl.fr/blockedcount1')->assertStatus(403);

    Queue::assertNothingPushed();
});

it('gives two links different hashes for the same visitor', function () {
    Queue::fake();

    $domain = Domain::factory()->forHost('iurl.fr')->create();
    $first = Link::factory()->forDomain($domain)->create(['slug' => 'correlate1']);
    $second = Link::factory()->forDomain($domain)->create(['slug' => 'correlate2']);

    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.77']);

    $this->get('https://iurl.fr/correlate1')->assertStatus(302);
    $this->get('https://iurl.fr/correlate2')->assertStatus(302);

    // A request that dispatches after the response re-runs the previous
    // terminating callbacks in the same test process, so the number of
    // captured jobs is not the count of clicks; what matters is that each
    // link carries its own, distinct, hash pair.
    $byLink = Queue::pushed(RecordClick::class)->groupBy('linkId');

    expect($byLink->keys()->sort()->values()->all())->toBe([$first->id, $second->id]);

    $firstJob = $byLink[$first->id]->first();
    $secondJob = $byLink[$second->id]->first();

    expect($firstJob->hashDaily)->not->toBe($secondJob->hashDaily);
    expect($firstJob->hashWeekly)->not->toBe($secondJob->hashWeekly);
});

it('still redirects when the queue is unavailable', function () {
    config([
        'database.redis.dead' => [
            'host' => '127.0.0.1',
            'port' => 6399,
            'database' => 0,
            'timeout' => 0.2,
            'read_timeout' => 0.2,
            'max_retries' => 0,
        ],
        'queue.connections.dead' => [
            'driver' => 'redis',
            'connection' => 'dead',
            'queue' => 'analytics',
            'retry_after' => 90,
            'block_for' => null,
        ],
        'queue.default' => 'dead',
    ]);

    $domain = Domain::factory()->forHost('iurl.fr')->create();
    Link::factory()->forDomain($domain)->create([
        'slug' => 'deadqueue1',
        'destination' => 'https://example.com/deadqueue',
    ]);

    $response = $this->withHeaders(['User-Agent' => ANALYTICS_IPHONE_UA])
        ->get('https://iurl.fr/deadqueue1');

    $response->assertStatus(302);
    $response->assertHeader('Location', 'https://example.com/deadqueue');
});

it('still redirects, with null hashes, and alerts when the salt is gone', function () {
    Queue::fake();

    $logFile = (string) tempnam(sys_get_temp_dir(), 'iurl-salt-log-');

    config([
        'logging.channels.salt_probe' => [
            'driver' => 'single',
            'path' => $logFile,
            'level' => 'debug',
        ],
        'logging.default' => 'salt_probe',
    ]);

    Log::forgetChannel('salt_probe');

    $domain = Domain::factory()->forHost('iurl.fr')->create();
    Link::factory()->forDomain($domain)->create([
        'slug' => 'nosalt1',
        'destination' => 'https://example.com/nosalt',
    ]);

    try {
        $this->deleteSaltFile();

        $this->withHeaders(['User-Agent' => ANALYTICS_IPHONE_UA])
            ->get('https://iurl.fr/nosalt1')
            ->assertStatus(302);

        $log = (string) file_get_contents($logFile);
    } finally {
        @unlink($logFile);
    }

    Queue::assertPushed(RecordClick::class, function (RecordClick $job): bool {
        return $job->hashDaily === null && $job->hashWeekly === null;
    });

    expect($log)->toContain('analytics salt unavailable');
    expect($log)->toContain('CRITICAL');
});
