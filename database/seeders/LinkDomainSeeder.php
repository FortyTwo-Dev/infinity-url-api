<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\DomainStatus;
use App\Models\Domain;
use App\Models\Workspace;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class LinkDomainSeeder extends Seeder
{
    public function run(): void
    {
        $workspace = Workspace::query()->firstOrCreate(
            ['slug' => 'system'],
            ['name' => 'Infinity URL'],
        );

        Domain::query()->firstOrCreate(
            ['hostname' => (string) config('redirect.host')],
            [
                'workspace_id' => $workspace->id,
                'verification_token' => Str::random(32),
                'status' => DomainStatus::Active,
                'verified_at' => now(),
                'tls_status' => 'valid',
                'tls_expires_at' => now()->addYear(),
                'last_checked_at' => now(),
            ],
        );
    }
}
