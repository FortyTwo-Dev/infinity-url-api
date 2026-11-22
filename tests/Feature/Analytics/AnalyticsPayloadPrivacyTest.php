<?php

use App\Jobs\RecordClick;
use App\Models\Domain;
use App\Models\Link;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;
use Tests\Support\AnalyticsHelpers;

uses(AnalyticsHelpers::class);

it('serializes no personal data in the analytics job', function () {
    Queue::fake();
    $this->travelTo(CarbonImmutable::parse('2026-11-21 10:00:00', 'UTC'));

    $domain = Domain::factory()->forHost('iurl.fr')->create();
    $link = Link::factory()->forDomain($domain)->create([
        'slug' => 'payloadprobe1',
        'destination' => 'https://privacy-probe.example/secret',
    ]);

    $this->withHeaders([
        'User-Agent' => 'PrivacyProbeUA/1.0',
        'Referer' => 'https://privacy-probe.example/secret',
    ])->withServerVariables([
        'REMOTE_ADDR' => '203.0.113.42',
    ])->get('https://iurl.fr/payloadprobe1')->assertStatus(302);

    $job = Queue::pushed(RecordClick::class)->first();

    expect($job)->toBeInstanceOf(RecordClick::class);

    $serialized = serialize($job);

    expect($serialized)->toContain('2026-11-21');
    expect($serialized)->toContain((string) $link->id);

    expect($serialized)->not->toContain('203.0.113.42');
    expect($serialized)->not->toContain('PrivacyProbeUA/1.0');
    expect($serialized)->not->toContain('https://privacy-probe.example/secret');
    expect($serialized)->not->toContain('payloadprobe1');
    expect($serialized)->not->toContain('iurl.fr');
    expect($serialized)->not->toContain('country');
});
