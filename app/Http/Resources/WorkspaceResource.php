<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Workspace;
use App\Support\ApiData;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Workspace
 */
final class WorkspaceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return ApiData::workspace($this->resource);
    }
}
