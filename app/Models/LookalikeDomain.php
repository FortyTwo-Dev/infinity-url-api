<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\LookalikeVariant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A precomputed variant of a protected brand. `variant_type` is what makes the
 * reason citable: "one letter away from laposte.fr", never "suspicious domain".
 */
#[Fillable(['domain', 'brand_domain', 'variant_type'])]
class LookalikeDomain extends Model
{
    protected $table = 'lookalike_domains';

    protected $primaryKey = 'domain';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'variant_type' => LookalikeVariant::class,
        ];
    }

    /**
     * @return BelongsTo<ProtectedBrand, $this>
     */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(ProtectedBrand::class, 'brand_domain', 'domain');
    }
}
