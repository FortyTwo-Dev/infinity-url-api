<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Auth\PurgeDeletedUsers;
use Illuminate\Console\Command;

final class PurgeDeletedUsersCommand extends Command
{
    protected $signature = 'users:purge-deleted';

    protected $description = 'Physically delete accounts whose grace period has expired.';

    public function handle(PurgeDeletedUsers $purge): int
    {
        $count = $purge->handle();

        $this->info('Purged '.$count.' account(s).');

        return self::SUCCESS;
    }
}
