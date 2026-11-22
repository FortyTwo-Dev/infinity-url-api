<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Abuse\PurgeReports;
use Illuminate\Console\Command;

final class PurgeReportsCommand extends Command
{
    protected $signature = 'abuse:purge-reports';

    protected $description = 'Delete reports older than twelve months, and rejected ones older than ninety days.';

    public function handle(PurgeReports $purge): int
    {
        $result = $purge->handle();

        $this->info('Purged '.$result['expired'].' expired report(s) and '.$result['rejected'].' rejected one(s).');

        return self::SUCCESS;
    }
}
