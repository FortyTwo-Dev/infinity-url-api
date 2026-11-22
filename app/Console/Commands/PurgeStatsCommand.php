<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Analytics\PurgeStats;
use Illuminate\Console\Command;

final class PurgeStatsCommand extends Command
{
    protected $signature = 'analytics:purge-stats';

    protected $description = 'Delete aggregated counters older than the retention window.';

    public function handle(PurgeStats $purge): int
    {
        $count = $purge->handle();

        $this->info('Purged '.$count.' aggregate row(s).');

        return self::SUCCESS;
    }
}
