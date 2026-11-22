<?php

use App\Enums\LookalikeVariant;
use App\Models\LookalikeDomain;
use App\Models\ProtectedBrand;
use App\Services\Abuse\BrandMatcher;
use Database\Seeders\BrandSeeder;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->seed(BrandSeeder::class);
});

it('matches a precomputed typosquat with its citable variant', function () {
    LookalikeDomain::query()->create([
        'domain' => 'leposte.fr',
        'brand_domain' => 'laposte.fr',
        'variant_type' => LookalikeVariant::Substitution,
    ]);

    $match = app(BrandMatcher::class)->match('www.leposte.fr');

    expect($match)->not->toBeNull()
        ->and($match->kind)->toBe('typosquat')
        ->and($match->brand)->toBe('laposte.fr')
        ->and($match->variant)->toBe(LookalikeVariant::Substitution)
        ->and($match->isBlocking())->toBeFalse()
        ->and($match->reason())->toBe('typosquat:substitution:laposte.fr');
});

it('blocks a homoglyph variant on its own', function () {
    $ascii = idn_to_ascii("l\u{0430}poste.fr", IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);

    LookalikeDomain::query()->create([
        'domain' => $ascii,
        'brand_domain' => 'laposte.fr',
        'variant_type' => LookalikeVariant::Homoglyph,
    ]);

    $match = app(BrandMatcher::class)->match($ascii);

    expect($match)->not->toBeNull()
        ->and($match->variant)->toBe(LookalikeVariant::Homoglyph)
        ->and($match->isBlocking())->toBeTrue();
});

it('blocks mixed scripts even without a table row', function () {
    $match = app(BrandMatcher::class)->match("l\u{0430}poste.fr");

    expect($match)->not->toBeNull()
        ->and($match->kind)->toBe('mixed_scripts')
        ->and($match->isBlocking())->toBeTrue();
});

it('blocks a brand buried in a third-party subdomain', function () {
    $match = app(BrandMatcher::class)->match('laposte.fr.connexion.example.com');

    expect($match)->not->toBeNull()
        ->and($match->kind)->toBe('brand_subdomain')
        ->and($match->brand)->toBe('laposte.fr')
        ->and($match->isBlocking())->toBeTrue();
});

it('blocks a public hostname buried in a third-party subdomain', function () {
    $match = app(BrandMatcher::class)->match('impots.gouv.fr.connexion.example.com');

    expect($match)->not->toBeNull()
        ->and($match->kind)->toBe('brand_subdomain')
        ->and($match->brand)->toBe('impots.gouv.fr');
});

it('leaves the brand own domain alone', function () {
    expect(app(BrandMatcher::class)->match('laposte.fr'))->toBeNull()
        ->and(app(BrandMatcher::class)->match('www.laposte.fr'))->toBeNull()
        ->and(app(BrandMatcher::class)->match('example.com'))->toBeNull();
});

it('rebuilds the lookalike table deterministically without shadowing a brand', function () {
    $this->artisan('abuse:rebuild-lookalikes')->assertSuccessful();

    $first = DB::table('lookalike_domains')->count();
    $this->artisan('abuse:rebuild-lookalikes')->assertSuccessful();

    expect($first)->toBeGreaterThan(1_000)
        ->and(DB::table('lookalike_domains')->count())->toBe($first)
        ->and(ProtectedBrand::query()->count())->toBe(46)
        ->and(DB::table('lookalike_domains')->whereIn('domain', ProtectedBrand::query()->pluck('domain'))->count())->toBe(0);
});
