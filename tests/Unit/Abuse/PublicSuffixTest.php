<?php

use App\Services\Abuse\PublicSuffix;
use Tests\TestCase;

uses(TestCase::class);

it('collapses a subdomain to its registrable domain', function () {
    $suffix = app(PublicSuffix::class);

    expect($suffix->registrable('a.b.example.co.uk'))->toBe('example.co.uk')
        ->and($suffix->registrable('example.co.uk'))->toBe('example.co.uk')
        ->and($suffix->registrable('www.laposte.fr'))->toBe('laposte.fr');
});

it('normalizes case, spaces and the trailing dot', function () {
    $suffix = app(PublicSuffix::class);

    expect($suffix->registrable('  WWW.LAPOSTE.FR. '))->toBe('laposte.fr');
});

it('rejects a host that is not a domain', function () {
    $suffix = app(PublicSuffix::class);

    expect($suffix->registrable('not a domain'))->toBeNull()
        ->and($suffix->registrable(''))->toBeNull();
});

it('falls back to the last two labels when the list is missing', function () {
    config(['abuse.psl_path' => '/nonexistent/public_suffix_list.dat']);

    $suffix = new PublicSuffix;

    expect($suffix->registrable('a.b.example.co.uk'))->toBe('co.uk');
});
