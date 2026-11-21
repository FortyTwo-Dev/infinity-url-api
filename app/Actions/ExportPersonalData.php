<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\User;
use App\Services\PersonalDataInventory;

final class ExportPersonalData
{
    public function __construct(private readonly PersonalDataInventory $inventory) {}

    /**
     * @return array<string, mixed>
     */
    public function handle(User $user): array
    {
        return $this->inventory->export($user);
    }
}
