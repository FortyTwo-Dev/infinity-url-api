<?php

use App\Actions\Abuse\PurgeReports;
use App\Models\Report;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

function purgeReport(string $state, CarbonImmutable $createdAt, ?CarbonImmutable $resolvedAt = null): string
{
    $publicId = (string) Str::ulid();

    DB::table('reports')->insert([
        'public_id' => $publicId,
        'submitted_host' => 'iurl.fr',
        'submitted_slug' => 'purge'.$publicId,
        'category' => 'other',
        'state' => $state,
        'resolution' => $state === Report::NEW ? null : 'qualification',
        'created_at' => $createdAt,
        'resolved_at' => $resolvedAt,
    ]);

    return $publicId;
}

it('purges a report older than twelve months whatever its state', function () {
    $now = CarbonImmutable::parse('2026-11-22');
    $old = purgeReport(Report::ACTIONED, $now->subMonths(13), $now->subMonths(13));
    $fresh = purgeReport(Report::NEW, $now->subMonths(2));

    $result = app(PurgeReports::class)->handle($now);

    expect($result['expired'])->toBe(1)
        ->and(DB::table('reports')->where('public_id', $old)->exists())->toBeFalse()
        ->and(DB::table('reports')->where('public_id', $fresh)->exists())->toBeTrue();
});

it('purges a rejected report after ninety days but keeps a recent one', function () {
    $now = CarbonImmutable::parse('2026-11-22');
    $old = purgeReport(Report::REJECTED, $now->subMonths(3), $now->subDays(100));
    $recent = purgeReport(Report::REJECTED, $now->subMonths(2), $now->subDays(10));

    $result = app(PurgeReports::class)->handle($now);

    expect($result['rejected'])->toBe(1)
        ->and(DB::table('reports')->where('public_id', $old)->exists())->toBeFalse()
        ->and(DB::table('reports')->where('public_id', $recent)->exists())->toBeTrue();
});

it('runs from the command line', function () {
    $this->artisan('abuse:purge-reports')
        ->expectsOutputToContain('Purged')
        ->assertSuccessful();
});
