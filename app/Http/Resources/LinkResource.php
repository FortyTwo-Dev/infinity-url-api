<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Link;
use App\Support\ApiData;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Link
 */
final class LinkResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return ApiData::link($this->resource);
    }
}
