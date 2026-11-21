<?php

declare(strict_types=1);

namespace App\Support;

use Carbon\CarbonImmutable;

final readonly class RedirectResult
{
    private function __construct(
        public int $status,
        public ?string $location = null,
        public ?string $reason = null,
        public ?CarbonImmutable $expiresAt = null,
    ) {}

    public static function redirect(string $location, ?CarbonImmutable $expiresAt = null): self
    {
        return new self(302, $location, null, $expiresAt);
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
            302 => $this->hasValidLocation(),
            410 => $this->reason === 'deleted' || $this->reason === 'expired',
            403, 404 => true,
            default => false,
        };
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

        return new self(
            $status,
            is_string($location) ? $location : null,
            is_string($reason) ? $reason : null,
        );
    }
}
