<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Database\Seeders\BrandSeeder;
use Illuminate\Console\Command;

final class ImportBrandsCommand extends Command
{
    protected $signature = 'abuse:import-brands';

    protected $description = 'Import the protected brands from resources/brands/brands.seed.csv.';

    public function handle(): int
    {
        $this->call('db:seed', [
            '--class' => BrandSeeder::class,
            '--force' => true,
        ]);

        $this->info('Protected brands imported.');

        return self::SUCCESS;
    }
}
