<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Abuse\RefreshBlocklists;
use Illuminate\Console\Command;

final class RefreshBlocklistsCommand extends Command
{
    protected $signature = 'abuse:refresh-blocklists';

    protected $description = 'Download the abuse dumps in full and refresh the local blocklist.';

    public function handle(RefreshBlocklists $refresh): int
    {
        $result = $refresh->handle();

        foreach ($result['sources'] as $source => $count) {
            $this->line($source.': '.$count.' domain(s)');
        }

        foreach ($result['failed'] as $source) {
            $this->warn($source.': download failed, keeping the previous entries');
        }

        $this->info('Purged '.$result['purged'].' stale domain(s).');

        return $result['sources'] === [] ? self::FAILURE : self::SUCCESS;
    }
}
