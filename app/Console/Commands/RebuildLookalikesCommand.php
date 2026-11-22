<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\ProtectedBrandType;
use App\Models\ProtectedBrand;
use App\Services\Abuse\BrandVariants;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Rebuilds the whole lookalike table from the protected brands.
 *
 * The table is derived data: it is truncated and rebuilt, never edited by hand.
 * A rebuild is deterministic, so a rerun produces the same rows.
 */
final class RebuildLookalikesCommand extends Command
{
    protected $signature = 'abuse:rebuild-lookalikes';

    protected $description = 'Regenerate every lookalike variant of the protected brands.';

    public function handle(BrandVariants $generator): int
    {
        $brands = ProtectedBrand::query()
            ->where('type', ProtectedBrandType::Registrable->value)
            ->orderBy('domain')
            ->pluck('domain')
            ->all();

        /** @var array<string, true> $protected */
        $protected = array_fill_keys(
            ProtectedBrand::query()->pluck('domain')->map(static fn (string $domain): string => strtolower($domain))->all(),
            true,
        );

        $rows = [];

        foreach ($brands as $brand) {
            foreach ($generator->variants($brand, $protected) as $domain => $variant) {
                $rows[] = [
                    'domain' => $domain,
                    'brand_domain' => $brand,
                    'variant_type' => $variant->value,
                ];
            }
        }

        DB::transaction(function () use ($rows): void {
            DB::table('lookalike_domains')->delete();

            foreach (array_chunk($rows, 2_000) as $chunk) {
                DB::table('lookalike_domains')->insert($chunk);
            }
        });

        $this->info('Generated '.count($rows).' lookalike domain(s) for '.count($brands).' brand(s).');

        return self::SUCCESS;
    }
}
