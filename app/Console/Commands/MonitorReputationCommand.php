<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Abuse\MonitorReputation;
use Illuminate\Console\Command;

final class MonitorReputationCommand extends Command
{
    protected $signature = 'abuse:monitor-reputation';

    protected $description = 'Check our own domains against the local abuse dumps and alert on ntfy.';

    public function handle(MonitorReputation $monitor): int
    {
        $result = $monitor->handle();

        $this->line('Checked: '.implode(', ', $result['domains']));

        if ($result['hits'] === []) {
            $this->info('No domain is listed in the local dumps.');

            return self::SUCCESS;
        }

        $this->warn('Listed in the local dumps: '.implode(', ', $result['hits']));
        $this->line('An alert was sent to ntfy.');

        return self::SUCCESS;
    }
}
