<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\DomainStatus;
use App\Models\Domain;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Domain>
 */
class DomainFactory extends Factory
{
    protected $model = Domain::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'hostname' => fake()->unique()->domainName(),
            'verification_token' => Str::random(32),
            'status' => DomainStatus::Active,
            'verified_at' => now(),
            'tls_status' => 'valid',
            'tls_expires_at' => now()->addYear(),
            'last_checked_at' => now(),
        ];
    }

    public function forHost(string $hostname): static
    {
        return $this->state(fn (): array => [
            'hostname' => $hostname,
        ]);
    }

    public function pending(): static
    {
        return $this->state(fn (): array => [
            'status' => 'pending',
            'verified_at' => null,
            'tls_status' => 'none',
            'tls_expires_at' => null,
        ]);
    }
}
