<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'slug',
    'reason',
])]
class ReservedSlug extends Model
{
    public $incrementing = false;

    public $timestamps = false;

    protected $primaryKey = 'slug';

    protected $keyType = 'string';
}
