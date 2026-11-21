<?php

declare(strict_types=1);

namespace App\Actions\Links;

use App\Enums\LinkStatus;
use App\Exceptions\ProblemException;
use App\Models\Domain;
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
            $locked = $this->lock($link);
            $this->assertEditable($locked);

            $domain = $locked->domain()->firstOrFail();
            $status = $this->status($locked, $data);
            $expiresAt = $this->expiresAt($locked, $data);

            $this->assertValidTransition($locked, $status, $expiresAt);

            return $this->save($locked, $this->attributes($locked, $data, $status, $expiresAt, $domain));
        });
    }

    private function lock(Link $link): Link
    {
        return Link::query()
            ->whereKey($link->id)
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function assertEditable(Link $link): void
    {
        if ($link->status === LinkStatus::Blocked) {
            throw ProblemException::linkBlocked();
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function status(Link $link, array $data): LinkStatus
    {
        return array_key_exists('status', $data)
            ? LinkStatus::from((string) $data['status'])
            : $link->status;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function expiresAt(Link $link, array $data): ?CarbonImmutable
    {
        if (! array_key_exists('expires_at', $data)) {
            return $link->expires_at;
        }

        return $data['expires_at'] === null
            ? null
            : CarbonImmutable::parse((string) $data['expires_at'])->utc();
    }

    private function assertValidTransition(
        Link $link,
        LinkStatus $status,
        ?CarbonImmutable $expiresAt,
    ): void {
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

        if ($link->status === LinkStatus::Disabled && $status === LinkStatus::Active) {
            $this->assertReactivation($link, $expiresAt);
        }
    }

    private function assertReactivation(Link $link, ?CarbonImmutable $expiresAt): void
    {
        if ($expiresAt === null || ! $expiresAt->isFuture()) {
            throw ValidationException::withMessages([
                'expires_at' => ['Reactivating a link requires a future expiration date.'],
            ]);
        }

        $workspace = Workspace::query()
            ->whereKey($link->workspace_id)
            ->lockForUpdate()
            ->firstOrFail();

        $this->quota->assertRoom($workspace);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function attributes(
        Link $link,
        array $data,
        LinkStatus $status,
        ?CarbonImmutable $expiresAt,
        Domain $domain,
    ): array {
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

        $this->applySlug($link, $data, $domain, $attributes);

        return $attributes;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $attributes
     */
    private function applySlug(Link $link, array $data, Domain $domain, array &$attributes): void
    {
        if (! array_key_exists('slug', $data) || ! is_string($data['slug']) || $data['slug'] === '') {
            return;
        }

        $slug = SlugRules::normalize($data['slug']);

        if ($slug === $link->slug) {
            return;
        }

        $this->slugRules->assertAvailable($domain, $slug, $link->id);

        DB::table('slug_tombstones')->insertOrIgnore([
            'domain_id' => $domain->id,
            'slug' => $link->slug,
            'erased_at' => now(),
        ]);

        $attributes['slug'] = $slug;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function save(Link $link, array $attributes): Link
    {
        try {
            $link->fill($attributes)->save();
        } catch (QueryException $exception) {
            if (($exception->errorInfo[0] ?? null) === '23505'
                || str_contains($exception->getMessage(), '23505')) {
                throw ProblemException::slugTaken((string) ($attributes['slug'] ?? $link->slug));
            }

            throw $exception;
        }

        return $link->load('domain');
    }
}
