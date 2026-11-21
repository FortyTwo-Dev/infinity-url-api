<?php

use App\Models\Domain;
use App\Models\Link;
use App\Services\ReservedSlugs;
use App\Services\SlugGenerator;
use Database\Seeders\LinkDomainSeeder;
use Illuminate\Support\Facades\DB;

const SLUG_GENERATION_ALPHABET = 'abcdefghijkmnopqrstuvwxyz23456789';

function slugGenerationDomain(): Domain
{
    test()->seed(LinkDomainSeeder::class);

    return Domain::query()->where('hostname', 'iurl.fr')->firstOrFail();
}

it('generates seven-character slugs from the lowercase alphabet', function () {
    $domain = slugGenerationDomain();
    $generator = app(SlugGenerator::class);

    $slugs = [];

    for ($i = 0; $i < 500; $i++) {
        $slugs[] = $generator->generate($domain);
    }

    expect($slugs)->toHaveCount(500);

    foreach ($slugs as $slug) {
        expect($slug)->toMatch('/^['.SLUG_GENERATION_ALPHABET.']{7}$/');
    }

    expect(count(array_unique($slugs)))->toBeGreaterThanOrEqual(490);
});

it('does not return the same slug twice in a row', function () {
    $domain = slugGenerationDomain();
    $generator = app(SlugGenerator::class);

    expect($generator->generate($domain))->not->toBe($generator->generate($domain));
});

it('maps every zero draw to aaaaaaa', function () {
    $domain = slugGenerationDomain();

    $generator = new SlugGenerator(
        app(ReservedSlugs::class),
        fn (int $max): int => 0,
    );

    expect($generator->generate($domain))->toBe('aaaaaaa');
});

it('retries with a fresh draw sequence when the slug already exists', function () {
    $domain = slugGenerationDomain();

    Link::factory()->forDomain($domain)->create(['slug' => 'aaaaaaa']);

    $draws = [0, 0, 0, 0, 0, 0, 0, 1, 0, 0, 0, 0, 0, 0];
    $position = 0;

    $generator = new SlugGenerator(
        app(ReservedSlugs::class),
        function (int $max) use (&$draws, &$position): int {
            return $draws[$position++] ?? 0;
        },
    );

    expect($generator->generate($domain))->toBe('baaaaaa');
});

it('retries when the slug is reserved', function () {
    $domain = slugGenerationDomain();

    DB::table('reserved_slugs')->insert(['slug' => 'aaaaaaa']);

    $draws = [0, 0, 0, 0, 0, 0, 0, 1, 0, 0, 0, 0, 0, 0];
    $position = 0;

    $generator = new SlugGenerator(
        app(ReservedSlugs::class),
        function (int $max) use (&$draws, &$position): int {
            return $draws[$position++] ?? 0;
        },
    );

    expect($generator->generate($domain))->toBe('baaaaaa');
});

it('retries when the slug is tombstoned', function () {
    $domain = slugGenerationDomain();

    DB::table('slug_tombstones')->insert([
        'domain_id' => $domain->id,
        'slug' => 'aaaaaaa',
        'erased_at' => now(),
    ]);

    $draws = [0, 0, 0, 0, 0, 0, 0, 1, 0, 0, 0, 0, 0, 0];
    $position = 0;

    $generator = new SlugGenerator(
        app(ReservedSlugs::class),
        function (int $max) use (&$draws, &$position): int {
            return $draws[$position++] ?? 0;
        },
    );

    expect($generator->generate($domain))->toBe('baaaaaa');
});
