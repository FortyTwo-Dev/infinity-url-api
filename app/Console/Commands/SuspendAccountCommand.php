<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Abuse\SuspendAccount;
use App\Models\User;
use App\Services\EmailHasher;
use Illuminate\Console\Command;

final class SuspendAccountCommand extends Command
{
    protected $signature = 'abuse:suspend-account
        {user : Public id or email of the account}
        {--reason= : Motive kept for the operator}';

    protected $description = 'Suspend an account: link creation is refused, the session and the export stay.';

    public function handle(SuspendAccount $action): int
    {
        $reason = trim((string) $this->option('reason'));

        if ($reason === '') {
            $this->error('A motive is required: --reason="..."');

            return self::FAILURE;
        }

        $identifier = (string) $this->argument('user');

        $user = User::query()->where('public_id', $identifier)->first();

        if ($user === null) {
            $user = User::query()
                ->where('email_hash', EmailHasher::hash($identifier))
                ->first();
        }

        if ($user === null) {
            $this->error('Account not found: '.$identifier);

            return self::FAILURE;
        }

        $action->handle($user);

        $this->info('Account '.$user->public_id.' suspended: '.$reason);
        $this->line('Link creation is refused; sign-in, tokens and export are preserved.');

        return self::SUCCESS;
    }
}
