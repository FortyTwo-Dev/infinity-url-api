<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Abuse\ProposeBrands;
use Illuminate\Console\Command;

final class ProposeBrandsCommand extends Command
{
    protected $signature = 'abuse:propose-brands';

    protected $description = 'Propose the brands actually imitated in the phishing dumps.';

    public function handle(ProposeBrands $propose): int
    {
        $result = $propose->handle();

        foreach ($result['failed'] as $source) {
            $this->warn($source.': download failed');
        }

        $this->info('Proposed '.$result['proposed'].' candidate token(s) for human review.');

        return self::SUCCESS;
    }
}
