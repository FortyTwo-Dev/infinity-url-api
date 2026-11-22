<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Analytics\AnalyticsSalt;
use Illuminate\Console\Command;

final class RotateAnalyticsSaltCommand extends Command
{
    protected $signature = 'analytics:rotate-salt';

    protected $description = 'Replace the analytics salt and destroy the previous value.';

    public function handle(AnalyticsSalt $salt): int
    {
        $salt->rotate();

        $this->info('Analytics salt rotated.');

        return self::SUCCESS;
    }
}
