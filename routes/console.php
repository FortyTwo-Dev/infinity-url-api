<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('sanctum:prune-expired --hours=24')->daily();
Schedule::command('users:purge-deleted')->daily();
Schedule::command('analytics:purge-stats')->dailyAt('03:15')->timezone('UTC');
Schedule::command('analytics:rotate-salt')->weeklyOn(1, '00:00')->timezone('UTC');
