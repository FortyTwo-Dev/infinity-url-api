<?php

declare(strict_types=1);

namespace App\Actions\Links;

use App\Enums\DomainStatus;
use App\Enums\LinkStatus;
use App\Exceptions\ProblemException;
use App\Models\Domain;
use App\Models\Link;
use App\Models\User;
use App\Models\Workspace;
use App\Services\LinkQuota;
use App\Services\SlugGenerator;
use App\Services\SlugRules;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

final class CreateLink
{
    public function __construct(
        private readonly SlugGenerator $slugGenerator,
        private readonly SlugRules $slugRules,
        private readonly LinkQuota $quota,
    ) {}

    /**
     * @param  array{destination: string, slug?: string|null, expires_at?: string|null}  $data
     */
    public function handle(User $user, Workspace $workspace, array $data): Link
    {
        if (! $user->isVerified()) {
            throw ProblemException::emailUnverified();
        }

        $domain = Domain::query()
            ->where('hostname', (string) config('redirect.host'))
            ->where('status', DomainStatus::Active)
            ->whereNotNull('verified_at')
            ->first();

        if ($domain === null) {
            throw ProblemException::serviceUnavailable();
        }

        $custom = isset($data['slug']) && is_string($data['slug']) && $data['slug'] !== '';
        $customSlug = $custom ? SlugRules::normalize((string) $data['slug']) : null;
        $expiresAt = isset($data['expires_at'])
            ? CarbonImmutable::parse((string) $data['expires_at'])->utc()
            : null;

        if ($customSlug !== null) {
            $this->slugRules->assertAvailable($domain, $customSlug);
        }

        $attempts = $customSlug === null ? 5 : 1;

        for ($attempt = 0; $attempt < $attempts; $attempt++) {
            $slug = $customSlug ?? $this->slugGenerator->generate($domain);

            try {
                return DB::transaction(function () use ($workspace, $domain, $slug, $data, $user, $expiresAt): Link {
                    $locked = Workspace::query()
                        ->whereKey($workspace->id)
                        ->lockForUpdate()
                        ->firstOrFail();

                    $this->quota->assertRoom($locked);

                    return Link::query()->create([
                        'workspace_id' => $locked->id,
                        'domain_id' => $domain->id,
                        'slug' => $slug,
                        'destination' => $data['destination'],
                        'status' => LinkStatus::Active,
                        'expires_at' => $expiresAt,
                        'created_by' => $user->id,
                    ]);
                });
            } catch (QueryException $exception) {
                if (! $this->isDuplicate($exception)) {
                    throw $exception;
                }

                if ($customSlug !== null) {
                    throw ProblemException::slugTaken($customSlug);
                }
            }
        }

        throw ProblemException::serviceUnavailable();
    }

    private function isDuplicate(QueryException $exception): bool
    {
        return ($exception->errorInfo[0] ?? null) === '23505'
            || str_contains($exception->getMessage(), '23505');
    }
}
