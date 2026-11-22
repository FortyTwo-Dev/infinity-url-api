<?php

use App\Models\Domain;
use App\Models\Link;
use Carbon\CarbonImmutable;
use Database\Seeders\LinkDomainSeeder;

it('lists only the links waiting for review', function () {
    $this->seed(LinkDomainSeeder::class);
    $domain = Domain::query()->where('hostname', 'iurl.fr')->firstOrFail();

    Link::factory()->forDomain($domain)->create([
        'slug' => 'flagged',
        'review_required_at' => CarbonImmutable::now()->subHour(),
        'review_reason' => 'typosquat:substitution:laposte.fr',
    ]);
    Link::factory()->forDomain($domain)->create(['slug' => 'clean']);

    $this->artisan('abuse:review-queue')
        ->expectsOutputToContain('flagged')
        ->doesntExpectOutputToContain('clean')
        ->assertSuccessful();
});

it('says so when the queue is empty', function () {
    $this->artisan('abuse:review-queue')
        ->expectsOutputToContain('No link is waiting for review.')
        ->assertSuccessful();
});
