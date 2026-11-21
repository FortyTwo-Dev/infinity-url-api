<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\LinkStatus;
use App\Models\Domain;
use App\Models\Link;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Link>
 */
class LinkFactory extends Factory
{
    protected $model = Link::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'domain_id' => Domain::factory(),
            'slug' => Str::lower(Str::random(7)),
            'destination' => 'https://example.com/'.Str::random(8),
            'status' => LinkStatus::Active,
            'expires_at' => null,
            'created_by' => null,
        ];
    }

    public function forDomain(Domain $domain): static
    {
        return $this->state(fn (): array => [
            'workspace_id' => $domain->workspace_id,
            'domain_id' => $domain->id,
        ]);
    }

    public function expired(): static
    {
        return $this->state(fn (): array => [
            'expires_at' => now()->subMinute(),
        ]);
    }

    public function disabled(): static
    {
        return $this->state(fn (): array => [
            'status' => LinkStatus::Disabled,
        ]);
    }

    public function blocked(): static
    {
        return $this->state(fn (): array => [
            'status' => LinkStatus::Blocked,
        ]);
    }
}
