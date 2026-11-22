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
use App\Services\Abuse\DestinationAssessment;
use App\Services\Abuse\DestinationInspector;
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
        private readonly DestinationInspector $inspector,
    ) {}

    /**
     * @param  array{destination: string, slug?: string|null, expires_at?: string|null}  $data
     */
    public function handle(User $user, Workspace $workspace, array $data): Link
    {
        if (! $user->isVerified()) {
            throw ProblemException::emailUnverified();
        }

        // The inspection runs before the transaction and before the quota: a
        // blocked destination is a validation failure, not a quota failure.
        $assessment = $this->inspector->inspect((string) $data['destination']);

        if ($assessment->isBlocking()) {
            throw $this->blocked($assessment);
        }

        $domain = $this->domain();
        $customSlug = $this->customSlug($data);
        $expiresAt = $this->expiresAt($data);

        if ($customSlug !== null) {
            $this->slugRules->assertAvailable($domain, $customSlug);
        }

        return $this->attempt($workspace, $domain, $customSlug, $data, $user, $expiresAt, $assessment);
    }

    private function blocked(DestinationAssessment $assessment): ProblemException
    {
        return $assessment->reason === 'private_address'
            ? ProblemException::destinationPrivateAddress($assessment->detail)
            : ProblemException::destinationBlocked($assessment->detail);
    }

    private function domain(): Domain
    {
        $domain = Domain::query()
            ->where('hostname', (string) config('redirect.host'))
            ->where('status', DomainStatus::Active)
            ->whereNotNull('verified_at')
            ->first();

        if ($domain === null) {
            throw ProblemException::serviceUnavailable();
        }

        return $domain;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function customSlug(array $data): ?string
    {
        if (! isset($data['slug']) || ! is_string($data['slug']) || $data['slug'] === '') {
            return null;
        }

        return SlugRules::normalize($data['slug']);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function expiresAt(array $data): ?CarbonImmutable
    {
        return isset($data['expires_at'])
            ? CarbonImmutable::parse((string) $data['expires_at'])->utc()
            : null;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function attempt(
        Workspace $workspace,
        Domain $domain,
        ?string $customSlug,
        array $data,
        User $user,
        ?CarbonImmutable $expiresAt,
        DestinationAssessment $assessment,
    ): Link {
        $attempts = $customSlug === null ? 5 : 1;

        for ($attempt = 0; $attempt < $attempts; $attempt++) {
            $slug = $customSlug ?? $this->slugGenerator->generate($domain);

            try {
                return $this->create($workspace, $domain, $slug, $data, $user, $expiresAt, $assessment);
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

    /**
     * @param  array<string, mixed>  $data
     */
    private function create(
        Workspace $workspace,
        Domain $domain,
        string $slug,
        array $data,
        User $user,
        ?CarbonImmutable $expiresAt,
        DestinationAssessment $assessment,
    ): Link {
        return DB::transaction(function () use ($workspace, $domain, $slug, $data, $user, $expiresAt, $assessment): Link {
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
                'review_required_at' => $assessment->requiresReview() ? CarbonImmutable::now('UTC') : null,
                'review_reason' => $assessment->requiresReview() ? $assessment->reason : null,
            ]);
        });
    }

    private function isDuplicate(QueryException $exception): bool
    {
        return ($exception->errorInfo[0] ?? null) === '23505'
            || str_contains($exception->getMessage(), '23505');
    }
}
