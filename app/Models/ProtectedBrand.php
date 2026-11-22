<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ProtectedBrandType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A brand worth protecting, sourced and dated. The list is short and
 * conservative: forgetting a brand is harmless, a wrong entry blocks a
 * legitimate link (D-132).
 */
#[Fillable(['domain', 'category', 'type', 'source', 'checked_at'])]
class ProtectedBrand extends Model
{
    protected $table = 'protected_brands';

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
            'type' => ProtectedBrandType::class,
            'checked_at' => 'immutable_date',
        ];
    }

    /**
     * @return HasMany<LookalikeDomain, $this>
     */
    public function lookalikes(): HasMany
    {
        return $this->hasMany(LookalikeDomain::class, 'brand_domain', 'domain');
    }
}
