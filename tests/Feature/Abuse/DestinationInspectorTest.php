<?php

use App\Enums\LookalikeVariant;
use App\Models\BlocklistDomain;
use App\Models\Domain;
use App\Models\Link;
use App\Models\LookalikeDomain;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Abuse\DnsResolver;
use App\Services\Abuse\TlsProbe;
use Carbon\CarbonImmutable;
use Database\Seeders\BrandSeeder;
use Database\Seeders\LinkDomainSeeder;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\Support\Abuse\FakeDnsResolver;
use Tests\Support\Abuse\FakeTlsProbe;
use Tests\Support\Api;

const TYPO_DESTINATION = 'https://leposte.fr/';

/**
 * @return array{0: Workspace, 1: User, 2: Domain, 3: FakeDnsResolver}
 */
function inspectionFixture(array $dns = [], ?CarbonImmutable $tlsIssuedAt = null): array
{
    test()->seed(LinkDomainSeeder::class);
    test()->seed(BrandSeeder::class);

    $workspace = Workspace::factory()->create();
    $user = User::factory()->create();
    $workspace->members()->attach($user->id, ['role' => 'owner']);
    Sanctum::actingAs($user, ['links:read', 'links:write']);

    $domain = Domain::query()->where('hostname', 'iurl.fr')->firstOrFail();
    $resolver = new FakeDnsResolver($dns);

    app()->instance(DnsResolver::class, $resolver);
    app()->instance(TlsProbe::class, new FakeTlsProbe($tlsIssuedAt));

    config(['abuse.enabled' => true]);

    return [$workspace, $user, $domain, $resolver];
}

function fakeRdap(?CarbonImmutable $registeredAt): void
{
    $events = $registeredAt === null
        ? []
        : [[
            'eventAction' => 'registration',
            'eventDate' => $registeredAt->toIso8601String(),
        ]];

    Http::fake([
        'https://rdap.org/domain/*' => Http::response(['events' => $events]),
        '*' => Http::response('', 200),
    ]);
}

function fakeTypo(): void
{
    LookalikeDomain::query()->create([
        'domain' => 'leposte.fr',
        'brand_domain' => 'laposte.fr',
        'variant_type' => LookalikeVariant::Substitution,
    ]);
}

function fakeBlocklistDomain(string $domain): void
{
    BlocklistDomain::query()->create([
        'domain' => $domain,
        'sources' => ['urlhaus'],
        'first_seen' => now()->subDay()->toDateString(),
        'last_seen' => now()->toDateString(),
    ]);
}

beforeEach(function () {
    Http::preventStrayRequests();
});

it('blocks a domain on the local blocklist', function () {
    inspectionFixture();
    fakeRdap(null);
    fakeBlocklistDomain('evil.test');

    $this->postJson(Api::LINKS, ['destination' => 'https://evil.test/page'])
        ->assertStatus(422)
        ->assertJsonPath('code', 'destination_blocked');

    expect(Link::query()->count())->toBe(0);
});

it('sends a typosquat alone to manual review without blocking it', function () {
    [, , $domain] = inspectionFixture();
    fakeRdap(CarbonImmutable::now()->subYears(5));
    fakeTypo();

    $response = $this->postJson(Api::LINKS, ['destination' => TYPO_DESTINATION]);

    $response->assertCreated()
        ->assertJsonPath('status', 'active')
        ->assertJsonMissingPath('review_required_at')
        ->assertJsonMissingPath('review_reason');

    $link = Link::query()->where('domain_id', $domain->id)->firstOrFail();

    expect($link->review_required_at)->not->toBeNull()
        ->and($link->review_reason)->toBe('typosquat:substitution:laposte.fr');
});

it('blocks a typosquat on a domain younger than thirty days', function () {
    inspectionFixture();
    fakeRdap(CarbonImmutable::now()->subDays(4));
    fakeTypo();

    $this->postJson(Api::LINKS, ['destination' => TYPO_DESTINATION])
        ->assertStatus(422)
        ->assertJsonPath('code', 'destination_blocked');
});

it('blocks a typosquat also present on the blocklist', function () {
    inspectionFixture();
    fakeRdap(null);
    fakeTypo();
    fakeBlocklistDomain('leposte.fr');

    $this->postJson(Api::LINKS, ['destination' => TYPO_DESTINATION])
        ->assertStatus(422)
        ->assertJsonPath('code', 'destination_blocked');
});

it('blocks a homoglyph destination', function () {
    inspectionFixture();
    fakeRdap(null);

    $host = idn_to_ascii("l\u{0430}poste.fr", IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);

    $this->postJson(Api::LINKS, ['destination' => 'https://'.$host.'/'])
        ->assertStatus(422)
        ->assertJsonPath('code', 'destination_blocked');
});

it('blocks a protected brand buried in a third-party subdomain', function () {
    inspectionFixture();
    fakeRdap(null);

    $this->postJson(Api::LINKS, ['destination' => 'https://laposte.fr.connexion.example.com/'])
        ->assertStatus(422)
        ->assertJsonPath('code', 'destination_blocked');
});

it('blocks a private address', function () {
    inspectionFixture(['private.test' => ['127.0.0.1']]);
    fakeRdap(null);

    $this->postJson(Api::LINKS, ['destination' => 'https://private.test/'])
        ->assertStatus(422)
        ->assertJsonPath('code', 'destination_private_address');
});

it('blocks the cloud metadata address', function () {
    inspectionFixture(['metadata.test' => ['169.254.169.254']]);
    fakeRdap(null);

    $this->postJson(Api::LINKS, ['destination' => 'https://metadata.test/'])
        ->assertStatus(422)
        ->assertJsonPath('code', 'destination_private_address');
});

it('fails open when DNS resolution fails', function () {
    inspectionFixture(['unresolved.test' => []]);
    fakeRdap(null);

    $this->postJson(Api::LINKS, ['destination' => 'https://unresolved.test/'])
        ->assertCreated();
});

it('fails open when the destination times out', function () {
    inspectionFixture();
    Http::fake([
        'https://rdap.org/domain/*' => Http::response(['events' => []]),
        '*' => Http::failedConnection(),
    ]);

    $this->postJson(Api::LINKS, ['destination' => 'https://slow.test/'])
        ->assertCreated();
});

it('sends a young domain with a login path to review', function () {
    inspectionFixture();
    fakeRdap(CarbonImmutable::now()->subDays(3));

    $response = $this->postJson(Api::LINKS, ['destination' => 'https://fresh-shop.test/login']);

    $response->assertCreated();

    expect(Link::query()->firstOrFail()->review_reason)->toBe('young_domain_login_path');
});

it('does not review a young domain without a sensitive path', function () {
    inspectionFixture();
    fakeRdap(CarbonImmutable::now()->subDays(3));

    $this->postJson(Api::LINKS, ['destination' => 'https://fresh-shop.test/about'])
        ->assertCreated();

    expect(Link::query()->firstOrFail()->review_required_at)->toBeNull();
});

it('inspects before the quota so a blocked destination is not reported as a quota failure', function () {
    [$workspace, , $domain] = inspectionFixture();
    fakeRdap(null);
    fakeBlocklistDomain('evil.test');

    config(['identity.limits.active_links' => 1]);
    Link::factory()->forDomain($domain)->create([
        'workspace_id' => $workspace->id,
        'status' => 'active',
    ]);

    $this->postJson(Api::LINKS, ['destination' => 'https://evil.test/'])
        ->assertStatus(422)
        ->assertJsonPath('code', 'destination_blocked');
});

it('counts a reviewed link towards the quota', function () {
    [$workspace, , $domain] = inspectionFixture();
    fakeRdap(CarbonImmutable::now()->subYears(2));
    fakeTypo();

    config(['identity.limits.active_links' => 1]);
    Link::factory()->forDomain($domain)->create([
        'workspace_id' => $workspace->id,
        'status' => 'active',
    ]);

    $this->postJson(Api::LINKS, ['destination' => TYPO_DESTINATION])
        ->assertStatus(409)
        ->assertJsonPath('code', 'quota_exceeded');
});

it('does not inspect at all when the check is disabled', function () {
    [, , , $resolver] = inspectionFixture();
    config(['abuse.enabled' => false]);
    fakeBlocklistDomain('evil.test');

    $this->postJson(Api::LINKS, ['destination' => 'https://evil.test/'])
        ->assertCreated();

    expect($resolver->calls)->toBe([]);
});
