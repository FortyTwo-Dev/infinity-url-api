<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An anonymous abuse report.
 *
 * It carries what the reporter typed and nothing about the reporter: no
 * address, no agent, no referrer, no rate-limit fingerprint. The contact email
 * is optional, encrypted at rest and never indexed (docs/04 §4.1 bis).
 *
 * @property string $public_id
 * @property string $state
 * @property string|null $resolution
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $resolved_at
 */
#[Fillable([
    'public_id',
    'link_id',
    'submitted_host',
    'submitted_slug',
    'category',
    'details',
    'contact_email',
    'state',
    'resolution',
    'resolved_at',
])]
#[Hidden(['contact_email'])]
class Report extends Model
{
    use HasPublicId;

    public const NEW = 'new';

    public const REVIEWING = 'reviewing';

    public const ACTIONED = 'actioned';

    public const REJECTED = 'rejected';

    protected $table = 'reports';

    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'contact_email' => 'encrypted',
            'created_at' => 'immutable_datetime',
            'resolved_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Link, $this>
     */
    public function link(): BelongsTo
    {
        return $this->belongsTo(Link::class);
    }
}
