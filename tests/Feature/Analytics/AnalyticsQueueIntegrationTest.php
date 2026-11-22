<?php

use App\Models\Domain;
use App\Models\Link;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Support\AnalyticsHelpers;

uses(AnalyticsHelpers::class);

it('defers the counters to the analytics worker', function () {
    config(['queue.default' => 'redis']);

    $domain = Domain::factory()->forHost('iurl.fr')->create();
    $link = Link::factory()->forDomain($domain)->create([
        'slug' => 'queued1',
        'destination' => 'https://example.com/queued',
    ]);

    $this->get('https://iurl.fr/queued1')->assertStatus(302);

    expect(DB::table('link_stats_daily')->where('link_id', $link->id)->count())->toBe(0);

    $this->artisan('queue:work', [
        'connection' => 'redis',
        '--once' => true,
        '--queue' => 'analytics',
    ])->assertExitCode(0);

    $row = DB::table('link_stats_daily')->where('link_id', $link->id)->first();

    expect($row)->not->toBeNull()
        ->and((int) $row->clicks)->toBe(1)
        ->and((int) $row->uniques)->toBe(1);
});

it('leaves the analytics queue alone when the worker listens to the default queue', function () {
    config(['queue.default' => 'redis']);

    $domain = Domain::factory()->forHost('iurl.fr')->create();
    $link = Link::factory()->forDomain($domain)->create([
        'slug' => 'queued2',
        'destination' => 'https://example.com/queued-two',
    ]);

    $this->get('https://iurl.fr/queued2')->assertStatus(302);

    expect(Queue::connection('redis')->size('analytics'))->toBe(1);

    $this->artisan('queue:work', [
        'connection' => 'redis',
        '--once' => true,
        '--queue' => 'default',
    ])->assertExitCode(0);

    expect(DB::table('link_stats_daily')->where('link_id', $link->id)->count())->toBe(0);
    expect(Queue::connection('redis')->size('analytics'))->toBe(1);
});
