<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\PersonalAccessToken;
use App\Support\ApiData;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin PersonalAccessToken
 */
final class TokenResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'scopes' => $this->abilities ?? [],
            'last_used_at' => ApiData::date($this->last_used_at),
            'expires_at' => ApiData::date($this->expires_at),
            'created_at' => ApiData::date($this->created_at),
        ];
    }
}
