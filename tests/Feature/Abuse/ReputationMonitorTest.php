<?php

use App\Models\BlocklistDomain;
use App\Models\Domain;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::preventStrayRequests();
    config(['abuse.ntfy_url' => 'https://ntfy.test/iurl-reputation']);
});

function listedDomain(string $domain): void
{
    BlocklistDomain::query()->create([
        'domain' => $domain,
        'sources' => ['urlhaus'],
        'first_seen' => CarbonImmutable::now()->subDay()->toDateString(),
        'last_seen' => CarbonImmutable::now()->toDateString(),
    ]);
}

it('alerts ntfy when the link domain is listed', function () {
    Http::fake();
    listedDomain('iurl.fr');

    $this->artisan('abuse:monitor-reputation')
        ->expectsOutputToContain('iurl.fr')
        ->assertSuccessful();

    Http::assertSent(static fn (Request $request): bool => $request->url() === 'https://ntfy.test/iurl-reputation'
        && str_contains((string) $request->body(), 'iurl.fr'));
});

it('checks the client domains too', function () {
    Http::fake();
    listedDomain('client.example');
    Workspace::factory()->create();

    Domain::factory()->create([
        'hostname' => 'client.example',
        'status' => 'active',
    ]);

    $this->artisan('abuse:monitor-reputation')
        ->expectsOutputToContain('client.example')
        ->assertSuccessful();

    Http::assertSentCount(1);
});

it('sends nothing when no domain is listed', function () {
    Http::fake();

    $this->artisan('abuse:monitor-reputation')
        ->expectsOutputToContain('No domain is listed')
        ->assertSuccessful();

    Http::assertNothingSent();
});
