<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\PostgresArray;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * One registrable domain seen in a public abuse dump.
 *
 * The table is local on purpose: no client URL is ever sent to a third party
 * (D-96). `sources` keeps every dump that listed the domain, and `last_seen`
 * drives the 30-day purge.
 */
#[Fillable(['domain', 'sources', 'first_seen', 'last_seen'])]
class BlocklistDomain extends Model
{
    protected $table = 'blocklist_domains';

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
            'sources' => PostgresArray::class,
            'first_seen' => 'immutable_date',
            'last_seen' => 'immutable_date',
        ];
    }
}
