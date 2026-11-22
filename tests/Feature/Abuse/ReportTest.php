<?php

use App\Models\Domain;
use App\Models\Link;
use App\Models\Report;
use App\Services\Analytics\AnalyticsSalt;
use Database\Seeders\LinkDomainSeeder;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\Api;

beforeEach(function () {
    Http::preventStrayRequests();
    Http::fake();
    config(['abuse.ntfy_url' => null]);
});

function reportPayload(array $overrides = []): array
{
    return [
        'slug' => 'unknown1',
        'category' => 'phishing',
        ...$overrides,
    ];
}

function knownLink(string $slug = 'known12'): Link
{
    test()->seed(LinkDomainSeeder::class);
    $domain = Domain::query()->where('hostname', 'iurl.fr')->firstOrFail();

    return Link::factory()->forDomain($domain)->create([
        'slug' => $slug,
        'destination' => 'https://example.com/target',
    ]);
}

it('accepts a report about an unknown slug and stores it', function () {
    $response = $this->postJson(Api::REPORT, reportPayload());

    $response->assertStatus(202)
        ->assertJsonPath('status', 'received');

    expect($response->json('reference'))->toBeString()->toHaveLength(26)
        ->and($response->headers->getCookies())->toBe([]);

    $report = Report::query()->firstOrFail();

    expect($report->link_id)->toBeNull()
        ->and($report->submitted_host)->toBe('iurl.fr')
        ->and($report->submitted_slug)->toBe('unknown1')
        ->and($report->category)->toBe('phishing')
        ->and($report->state)->toBe('new')
        ->and($report->public_id)->toBe($response->json('reference'));
});

it('resolves a known link', function () {
    $link = knownLink();

    $this->postJson(Api::REPORT, reportPayload(['slug' => 'known12']))
        ->assertStatus(202);

    expect(Report::query()->firstOrFail()->link_id)->toBe($link->id);
});

it('resolves a soft deleted link', function () {
    $link = knownLink();
    $link->delete();

    $this->postJson(Api::REPORT, reportPayload(['slug' => 'known12']))
        ->assertStatus(202);

    expect(Report::query()->firstOrFail()->link_id)->toBe($link->id);
});

it('accepts a pasted short url as well as a bare slug', function () {
    $link = knownLink('known12');

    $this->postJson(Api::REPORT, reportPayload(['slug' => 'https://iurl.fr/known12']))
        ->assertStatus(202);

    expect(Report::query()->firstOrFail()->link_id)->toBe($link->id);
});

it('answers 202 and writes nothing when the honeypot is filled', function () {
    $response = $this->postJson(Api::REPORT, reportPayload(['website' => 'https://spam.example']));

    $response->assertStatus(202)
        ->assertJsonPath('status', 'received');

    expect($response->json('reference'))->toBeString()
        ->and(Report::query()->count())->toBe(0);
});

it('rejects an unknown category', function () {
    $this->postJson(Api::REPORT, reportPayload(['category' => 'spam']))
        ->assertStatus(422)
        ->assertJsonPath('code', 'validation_failed');
});

it('rejects a malformed short link', function () {
    $this->postJson(Api::REPORT, reportPayload(['slug' => 'not a link!']))
        ->assertStatus(422)
        ->assertJsonPath('code', 'validation_failed')
        ->assertJsonPath('errors.slug.0', 'The short link is invalid.');
});

it('rejects details longer than two thousand characters', function () {
    $this->postJson(Api::REPORT, reportPayload(['details' => str_repeat('a', 2001)]))
        ->assertStatus(422)
        ->assertJsonPath('code', 'validation_failed');
});

it('rate limits to five reports per hour', function () {
    for ($attempt = 0; $attempt < 5; $attempt++) {
        $this->postJson(Api::REPORT, reportPayload())->assertStatus(202);
    }

    $response = $this->postJson(Api::REPORT, reportPayload());

    $response->assertStatus(429)
        ->assertJsonPath('code', 'rate_limited')
        ->assertHeader('Retry-After');

    expect(Report::query()->count())->toBe(5);
});

it('does not rate limit when the analytics salt is missing', function () {
    config(['analytics.salt_path' => '/nonexistent/analytics.salt']);
    app()->forgetInstance(AnalyticsSalt::class);

    for ($attempt = 0; $attempt < 6; $attempt++) {
        $this->postJson(Api::REPORT, reportPayload())->assertStatus(202);
    }

    expect(Report::query()->count())->toBe(6);
});

it('encrypts the optional contact email at rest', function () {
    $this->postJson(Api::REPORT, reportPayload(['email' => 'reporter@example.com']))
        ->assertStatus(202);

    $raw = (string) DB::table('reports')->value('contact_email');

    expect($raw)->not->toBe('')
        ->and($raw)->not->toContain('reporter@example.com')
        ->and(Report::query()->firstOrFail()->contact_email)->toBe('reporter@example.com');
});

it('stores nothing about the reporter beyond the optional email', function () {
    $columns = collect(DB::select(
        "select column_name from information_schema.columns where table_name = 'reports'"
    ))->pluck('column_name')->all();

    foreach (['ip_address', 'user_agent', 'referer', 'referrer', 'fingerprint', 'session_id'] as $forbidden) {
        expect($columns)->not->toContain($forbidden);
    }

    expect($columns)->toContain('contact_email');
});

it('alerts ntfy with the reference and the slug, never the reporter', function () {
    config(['abuse.ntfy_url' => 'https://ntfy.test/iurl-abuse']);
    Http::fake();

    $this->postJson(Api::REPORT, reportPayload([
        'slug' => 'known12',
        'details' => 'Received by SMS',
        'email' => 'reporter@example.com',
    ]))->assertStatus(202);

    Http::assertSent(function (ClientRequest $request): bool {
        $body = (string) $request->body();

        return $request->url() === 'https://ntfy.test/iurl-abuse'
            && str_contains($body, 'known12')
            && ! str_contains($body, 'reporter@example.com')
            && ! str_contains($body, 'Received by SMS');
    });
});
