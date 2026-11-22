<?php

declare(strict_types=1);

namespace App\Support;

use Carbon\CarbonImmutable;
use LogicException;

final readonly class RedirectResult
{
    private function __construct(
        public int $status,
        public ?string $location = null,
        public ?string $reason = null,
        public ?CarbonImmutable $expiresAt = null,
        public ?int $linkId = null,
    ) {}

    public static function redirect(
        string $location,
        ?CarbonImmutable $expiresAt = null,
        ?int $linkId = null,
    ): self {
        return new self(302, $location, null, $expiresAt, $linkId);
    }

    public static function notFound(): self
    {
        return new self(404);
    }

    public static function goneDeleted(): self
    {
        return new self(410, null, 'deleted');
    }

    public static function goneExpired(): self
    {
        return new self(410, null, 'expired');
    }

    public static function blocked(): self
    {
        return new self(403);
    }

    public function isValid(): bool
    {
        return match ($this->status) {
            // A cached 302 without a link id cannot feed the analytics job and
            // is treated as a miss: the next click reloads it from Postgres.
            302 => $this->hasValidLocation() && $this->linkId !== null,
            410 => $this->reason === 'deleted' || $this->reason === 'expired',
            403, 404 => true,
            default => false,
        };
    }

    public function locationOrFail(): string
    {
        if ($this->location === null) {
            throw new LogicException('A redirect response requires a location.');
        }

        return $this->location;
    }

    private function hasValidLocation(): bool
    {
        if ($this->location === null) {
            return false;
        }

        $location = strtolower($this->location);

        return (str_starts_with($location, 'http://') || str_starts_with($location, 'https://'))
            && preg_match('/[\x00-\x1F\x7F]/', $this->location) !== 1;
    }

    /**
     * @return array{status: int, location?: string, reason?: string}
     */
    public function toArray(): array
    {
        $payload = ['status' => $this->status];

        if ($this->location !== null) {
            $payload['location'] = $this->location;
        }

        if ($this->reason !== null) {
            $payload['reason'] = $this->reason;
        }

        if ($this->linkId !== null) {
            $payload['link_id'] = $this->linkId;
        }

        return $payload;
    }

    /**
     * @param  array<array-key, mixed>  $payload
     */
    public static function tryFromArray(array $payload): ?self
    {
        $status = $payload['status'] ?? null;

        if (! is_int($status)) {
            return null;
        }

        $location = $payload['location'] ?? null;
        $reason = $payload['reason'] ?? null;
        $linkId = $payload['link_id'] ?? null;

        return new self(
            $status,
            is_string($location) ? $location : null,
            is_string($reason) ? $reason : null,
            null,
            is_int($linkId) ? $linkId : null,
        );
    }
}
