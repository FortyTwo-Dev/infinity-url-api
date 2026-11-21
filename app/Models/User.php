<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\EncryptedWithHash;
use App\Models\Concerns\HasPublicId;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable([
    'public_id',
    'email',
    'password_hash',
    'email_verified_at',
    'totp_secret',
    'totp_confirmed_at',
])]
#[Hidden([
    'email_hash',
    'password_hash',
    'totp_secret',
])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasPublicId, Notifiable, SoftDeletes;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email' => EncryptedWithHash::class,
            'password_hash' => 'hashed',
            'totp_secret' => 'encrypted',
            'email_verified_at' => 'immutable_datetime',
            'totp_confirmed_at' => 'immutable_datetime',
            'deleted_at' => 'immutable_datetime',
        ];
    }

    public function getAuthPassword(): string
    {
        return (string) $this->password_hash;
    }

    /**
     * @return BelongsToMany<Workspace, $this>
     */
    public function workspaces(): BelongsToMany
    {
        return $this->belongsToMany(Workspace::class, 'workspace_members')
            ->withPivot('role');
    }

    public function workspace(): ?Workspace
    {
        return $this->workspaces()->first();
    }

    /**
     * @return HasMany<RecoveryCode, $this>
     */
    public function recoveryCodes(): HasMany
    {
        return $this->hasMany(RecoveryCode::class);
    }

    /**
     * API and session tokens, typed with our Sanctum model.
     *
     * @return MorphMany<PersonalAccessToken, $this>
     */
    public function apiTokens(): MorphMany
    {
        return $this->morphMany(PersonalAccessToken::class, 'tokenable');
    }

    public function isVerified(): bool
    {
        return $this->email_verified_at !== null;
    }

    public function hasTotp(): bool
    {
        return $this->totp_confirmed_at !== null && $this->totp_secret !== null;
    }
}
