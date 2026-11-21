<?php

declare(strict_types=1);

namespace App\Actions\Links;

use App\Enums\LinkStatus;
use App\Exceptions\ProblemException;
use App\Models\Link;
use App\Models\Workspace;
use App\Services\LinkQuota;
use App\Services\SlugRules;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class UpdateLink
{
    public function __construct(
        private readonly SlugRules $slugRules,
        private readonly LinkQuota $quota,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(Link $link, array $data): Link
    {
        return DB::transaction(function () use ($link, $data): Link {
            $locked = Link::query()
                ->whereKey($link->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->status === LinkStatus::Blocked) {
                throw ProblemException::linkBlocked();
            }

            $domain = $locked->domain()->firstOrFail();

            $status = array_key_exists('status', $data)
                ? LinkStatus::from((string) $data['status'])
                : $locked->status;

            $expiresAt = array_key_exists('expires_at', $data)
                ? ($data['expires_at'] === null
                    ? null
                    : CarbonImmutable::parse((string) $data['expires_at'])->utc())
                : $locked->expires_at;

            if ($status === LinkStatus::Blocked) {
                throw ValidationException::withMessages([
                    'status' => ['A link cannot be blocked by its owner.'],
                ]);
            }

            if ($status === LinkStatus::Active && $expiresAt !== null && ! $expiresAt->isFuture()) {
                throw ValidationException::withMessages([
                    'expires_at' => ['The expiration date must be in the future.'],
                ]);
            }

            if ($locked->status === LinkStatus::Disabled && $status === LinkStatus::Active) {
                if ($expiresAt === null || ! $expiresAt->isFuture()) {
                    throw ValidationException::withMessages([
                        'expires_at' => ['Reactivating a link requires a future expiration date.'],
                    ]);
                }

                $workspace = Workspace::query()
                    ->whereKey($locked->workspace_id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $this->quota->assertRoom($workspace);
            }

            $attributes = [];

            if (array_key_exists('destination', $data)) {
                $attributes['destination'] = $data['destination'];
            }

            if (array_key_exists('expires_at', $data)) {
                $attributes['expires_at'] = $expiresAt;
            }

            if (array_key_exists('status', $data)) {
                $attributes['status'] = $status;
            }

            if (array_key_exists('slug', $data) && is_string($data['slug']) && $data['slug'] !== '') {
                $slug = SlugRules::normalize($data['slug']);

                if ($slug !== $locked->slug) {
                    $this->slugRules->assertAvailable($domain, $slug, $locked->id);

                    DB::table('slug_tombstones')->insertOrIgnore([
                        'domain_id' => $domain->id,
                        'slug' => $locked->slug,
                        'erased_at' => now(),
                    ]);

                    $attributes['slug'] = $slug;
                }
            }

            try {
                $locked->fill($attributes)->save();
            } catch (QueryException $exception) {
                if (($exception->errorInfo[0] ?? null) === '23505'
                    || str_contains($exception->getMessage(), '23505')) {
                    throw ProblemException::slugTaken((string) ($attributes['slug'] ?? $locked->slug));
                }

                throw $exception;
            }

            return $locked->load('domain');
        });
    }
}
