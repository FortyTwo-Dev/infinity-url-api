<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\LinkStatus;
use App\Models\Concerns\HasPublicId;
use App\Observers\LinkObserver;
use Carbon\CarbonImmutable;
use Database\Factories\LinkFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $id
 * @property int $workspace_id
 * @property int $domain_id
 * @property string $slug
 * @property string $destination
 * @property LinkStatus $status
 * @property CarbonImmutable|null $expires_at
 */
#[ObservedBy(LinkObserver::class)]
#[Fillable([
    'public_id',
    'workspace_id',
    'domain_id',
    'slug',
    'destination',
    'status',
    'expires_at',
    'created_by',
    'review_required_at',
    'review_reason',
])]
class Link extends Model
{
    /** @use HasFactory<LinkFactory> */
    use HasFactory, HasPublicId, SoftDeletes;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => LinkStatus::class,
            'expires_at' => 'immutable_datetime',
            'review_required_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Workspace, $this>
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /**
     * @return BelongsTo<Domain, $this>
     */
    public function domain(): BelongsTo
    {
        return $this->belongsTo(Domain::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
