<?php

use App\Services\Analytics\AnalyticsSalt;
use Carbon\CarbonImmutable;
use Cron\CronExpression;
use Illuminate\Console\Scheduling\Schedule;

it('rotates the salt and destroys the previous one', function () {
    $salt = app(AnalyticsSalt::class);
    $path = $salt->path();

    $before = (string) file_get_contents($path);

    expect($before)->toMatch('/^[0-9a-f]{64}$/');

    $this->artisan('analytics:rotate-salt')->assertSuccessful();

    clearstatcache();

    $after = (string) file_get_contents($path);

    expect($after)->toMatch('/^[0-9a-f]{64}$/');
    expect($after)->not->toBe($before);
    expect(substr(sprintf('%o', fileperms($path)), -4))->toBe('0600');

    $directory = dirname($path);
    $files = array_values(array_filter(
        array_diff(scandir($directory) ?: [], ['.', '..']),
        static fn (string $file): bool => is_file($directory.DIRECTORY_SEPARATOR.$file),
    ));

    expect($files)->toHaveCount(1);

    foreach ($files as $file) {
        expect((string) file_get_contents($directory.DIRECTORY_SEPARATOR.$file))->not->toContain($before);
    }
});

it('serves the rotated salt immediately and rotates again within the same second', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-23 00:00:00', 'UTC'));

    $salt = app(AnalyticsSalt::class);

    $this->artisan('analytics:rotate-salt')->assertSuccessful();

    $first = $salt->current();

    expect($first)->toBeString()->toMatch('/^[0-9a-f]{64}$/');

    $this->artisan('analytics:rotate-salt')->assertSuccessful();

    $second = $salt->current();

    expect($second)->toBeString()->toMatch('/^[0-9a-f]{64}$/');
    expect($second)->not->toBe($first);
});

it('schedules the rotation weekly and the purge daily', function () {
    $events = collect(app(Schedule::class)->events());

    $rotate = $events->first(
        static fn ($event): bool => is_string($event->command) && str_contains($event->command, 'analytics:rotate-salt'),
    );

    $purge = $events->first(
        static fn ($event): bool => is_string($event->command) && str_contains($event->command, 'analytics:purge-stats'),
    );

    expect($rotate)->not->toBeNull();
    expect($purge)->not->toBeNull();
    expect($rotate->expression)->toBe('0 0 * * 1');

    $cron = new CronExpression($purge->expression);
    $first = $cron->getNextRunDate('2026-11-22 00:00:00', 0, false, 'UTC');
    $second = $cron->getNextRunDate($first->format('Y-m-d H:i:s'), 0, false, 'UTC');

    expect($second->getTimestamp() - $first->getTimestamp())->toBe(86_400);
});
