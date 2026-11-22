<?php

use App\Actions\Abuse\ProposeBrands;
use App\Models\BrandProposal;
use App\Models\ProtectedBrand;
use Carbon\CarbonImmutable;
use Database\Seeders\BrandSeeder;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->seed(BrandSeeder::class);
});

it('proposes the labels actually imitated in the phishing dump', function () {
    $result = app(ProposeBrands::class)->handle(CarbonImmutable::parse('2026-11-22'), [
        'https://nouvelmarque.fr.connexion.evil.com/verify',
        'https://nouvelmarque.fr.secure.evil2.com/login',
        'https://paypal.com.verify.evil3.com/',
        'https://www.example.com/',
    ]);

    expect($result['proposed'])->toBe(1)
        ->and(BrandProposal::query()->where('token', 'nouvelmarque')->value('occurrences'))->toBe(2)
        ->and(BrandProposal::query()->where('token', 'nouvelmarque')->value('state'))->toBe('proposed')
        ->and(BrandProposal::query()->where('token', 'paypal')->exists())->toBeFalse()
        ->and(BrandProposal::query()->where('token', 'connexion')->exists())->toBeFalse()
        ->and(BrandProposal::query()->where('token', 'www')->exists())->toBeFalse();
});

it('never writes to the protected brands', function () {
    $before = ProtectedBrand::query()->orderBy('domain')->pluck('domain')->all();

    app(ProposeBrands::class)->handle(CarbonImmutable::parse('2026-11-22'), [
        'https://nouvelmarque.fr.connexion.evil.com/verify',
        'https://nouvelmarque.fr.secure.evil2.com/login',
    ]);

    expect(ProtectedBrand::query()->orderBy('domain')->pluck('domain')->all())->toBe($before);
});

it('reports the failed sources and still proposes from the others', function () {
    $sources = config('abuse.sources');

    Http::fake([
        $sources['phishing_army']['url'] => Http::response('', 500),
        $sources['openphish']['url'] => Http::response(
            implode("\n", [
                'https://nouvelmarque.fr.connexion.evil.com/verify',
                'https://nouvelmarque.fr.secure.evil2.com/login',
            ]),
        ),
    ]);

    $result = app(ProposeBrands::class)->handle(CarbonImmutable::parse('2026-11-22'));

    expect($result['failed'])->toBe(['phishing_army'])
        ->and(BrandProposal::query()->where('token', 'nouvelmarque')->value('occurrences'))->toBe(2);
});
