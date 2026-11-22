<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Link;
use App\Models\User;
use App\Models\Workspace;
use Carbon\CarbonInterface;

final class ApiData
{
    /**
     * @return array<string, mixed>
     */
    public static function user(User $user): array
    {
        return [
            'id' => $user->public_id,
            'email' => $user->email,
            'email_verified' => $user->isVerified(),
            'totp_enabled' => $user->hasTotp(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function workspace(Workspace $workspace): array
    {
        return [
            'id' => $workspace->public_id,
            'name' => $workspace->name,
            'plan' => 'free',
            'limits' => [
                'active_links' => (int) config('identity.limits.active_links'),
                'custom_domains' => (int) config('identity.limits.custom_domains'),
                'stats_retention_days' => (int) config('identity.limits.stats_retention_days'),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function link(Link $link): array
    {
        $host = $link->relationLoaded('domain')
            ? $link->domain?->hostname
            : $link->domain()->value('hostname');

        $base = config('redirect.url');
        $shortUrl = is_string($base) && $base !== ''
            ? rtrim($base, '/').'/'.$link->slug
            : 'https://'.($host ?? config('redirect.host')).'/'.$link->slug;

        return [
            'id' => $link->public_id,
            'slug' => $link->slug,
            'destination' => $link->destination,
            'short_url' => $shortUrl,
            'status' => $link->status->value,
            'expires_at' => self::date($link->expires_at),
            'created_at' => self::date($link->created_at),
            'updated_at' => self::date($link->updated_at),
        ];
    }

    public static function date(?CarbonInterface $date): ?string
    {
        return $date?->toISOString();
    }
}
