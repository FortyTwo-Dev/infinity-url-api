<?php

use App\Actions\Abuse\RefreshBlocklists;
use App\Models\BlocklistDomain;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

const REFRESH_TODAY = '2026-11-22';
const REFRESH_FIRST_SEEN = '2026-01-01';

function abuseFixture(string $name): string
{
    return (string) file_get_contents(base_path('tests/Fixtures/abuse/'.$name));
}

function fakeDumps(?string $urlhausStatus = 'ok'): void
{
    $sources = config('abuse.sources');

    Http::fake([
        $sources['urlhaus']['url'] => $urlhausStatus === 'fail'
            ? Http::response('', 503)
            : Http::response(abuseFixture('urlhaus.txt')),
        $sources['phishing_army']['url'] => Http::response(abuseFixture('phishing_army.txt')),
        $sources['openphish']['url'] => Http::response(abuseFixture('openphish.txt')),
    ]);
}

it('stores registrable domains and merges their sources', function () {
    fakeDumps();

    $result = app(RefreshBlocklists::class)->handle(CarbonImmutable::parse(REFRESH_TODAY));

    $entry = BlocklistDomain::query()->find('example.co.uk');

    expect($result['failed'])->toBe([])
        ->and($entry)->not->toBeNull()
        ->and($entry?->sources)->toBe(['openphish', 'urlhaus'])
        ->and($entry?->last_seen->toDateString())->toBe(REFRESH_TODAY)
        ->and(BlocklistDomain::query()->whereKey('192.168.1.10')->exists())->toBeFalse()
        ->and(BlocklistDomain::query()->whereKey('bad.test')->exists())->toBeTrue();
});

it('keeps the first seen date and unions the sources on update', function () {
    BlocklistDomain::query()->create([
        'domain' => 'example.co.uk',
        'sources' => ['urlhaus'],
        'first_seen' => REFRESH_FIRST_SEEN,
        'last_seen' => REFRESH_FIRST_SEEN,
    ]);

    fakeDumps();
    app(RefreshBlocklists::class)->handle(CarbonImmutable::parse(REFRESH_TODAY));

    $entry = BlocklistDomain::query()->findOrFail('example.co.uk');

    expect($entry->first_seen->toDateString())->toBe(REFRESH_FIRST_SEEN)
        ->and($entry->last_seen->toDateString())->toBe(REFRESH_TODAY)
        ->and($entry->sources)->toBe(['openphish', 'urlhaus']);
});

it('purges an entry absent from every dump for more than thirty days', function () {
    BlocklistDomain::query()->create([
        'domain' => 'stale.example',
        'sources' => ['urlhaus'],
        'first_seen' => REFRESH_FIRST_SEEN,
        'last_seen' => '2026-10-01',
    ]);

    fakeDumps();
    $result = app(RefreshBlocklists::class)->handle(CarbonImmutable::parse(REFRESH_TODAY));

    expect($result['purged'])->toBe(1)
        ->and(BlocklistDomain::query()->whereKey('stale.example')->exists())->toBeFalse();
});

it('keeps the previous entries when a dump fails', function () {
    BlocklistDomain::query()->create([
        'domain' => 'kept.example',
        'sources' => ['urlhaus'],
        'first_seen' => '2026-11-01',
        'last_seen' => '2026-11-21',
    ]);

    fakeDumps('fail');
    $result = app(RefreshBlocklists::class)->handle(CarbonImmutable::parse(REFRESH_TODAY));

    expect($result['failed'])->toBe(['urlhaus'])
        ->and($result['purged'])->toBe(0)
        ->and(BlocklistDomain::query()->whereKey('kept.example')->exists())->toBeTrue();
});

it('sends the urlhaus auth key when one is configured', function () {
    config(['abuse.sources.urlhaus.auth_key' => 'secret-key']);
    fakeDumps();

    app(RefreshBlocklists::class)->handle(CarbonImmutable::parse(REFRESH_TODAY));

    Http::assertSent(static fn (Request $request): bool => str_contains($request->url(), 'urlhaus')
        && $request->hasHeader('Auth-Key', 'secret-key'));
});

it('runs from the command line', function () {
    fakeDumps();

    $this->artisan('abuse:refresh-blocklists')
        ->expectsOutputToContain('Purged')
        ->assertSuccessful();

    expect(BlocklistDomain::query()->count())->toBeGreaterThan(0);
});
