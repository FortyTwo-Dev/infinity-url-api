<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * A candidate brand extracted from a phishing dump. A job proposes, a human
 * accepts; nothing here ever writes to `protected_brands` (D-132).
 */
#[Fillable(['token', 'occurrences', 'window_from', 'window_to', 'state'])]
class BrandProposal extends Model
{
    public const PROPOSED = 'proposed';

    public const ACCEPTED = 'accepted';

    public const REJECTED = 'rejected';

    protected $table = 'brand_proposals';

    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'occurrences' => 'integer',
            'window_from' => 'immutable_date',
            'window_to' => 'immutable_date',
            'created_at' => 'immutable_datetime',
        ];
    }
}
