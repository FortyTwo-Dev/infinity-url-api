<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\ProtectedBrand;
use Illuminate\Database\Seeder;

/**
 * Imports `resources/brands/brands.seed.csv`. The file is the source of truth:
 * a brand is added there, with its source and its verification date, then
 * imported. Nothing is invented here (D-132).
 */
class BrandSeeder extends Seeder
{
    public function run(): void
    {
        $rows = $this->rows();

        if ($rows === []) {
            return;
        }

        ProtectedBrand::query()->upsert(
            $rows,
            ['domain'],
            ['category', 'type', 'source', 'checked_at'],
        );
    }

    /**
     * @return list<array{domain: string, category: string, type: string, source: string, checked_at: string}>
     */
    private function rows(): array
    {
        $path = resource_path('brands/brands.seed.csv');
        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            return [];
        }

        $rows = [];
        $header = true;

        try {
            while (($line = fgets($handle)) !== false) {
                $line = trim($line);

                if ($line === '' || str_starts_with($line, '#')) {
                    continue;
                }

                if ($header) {
                    $header = false;

                    continue;
                }

                $columns = array_map('trim', explode(',', $line));

                if (count($columns) < 5 || $columns[0] === '') {
                    continue;
                }

                $rows[] = [
                    'domain' => strtolower($columns[0]),
                    'category' => $columns[1],
                    'type' => $columns[2],
                    'source' => $columns[3],
                    'checked_at' => $columns[4],
                ];
            }
        } finally {
            fclose($handle);
        }

        return $rows;
    }
}
