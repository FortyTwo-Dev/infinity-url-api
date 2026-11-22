<?php

declare(strict_types=1);

namespace App\Services\Abuse;

use App\Enums\DestinationOutcome;

/**
 * The result of one destination check.
 *
 * `reason` is the machine code stored on the link and read by the operator;
 * `detail` is the citable sentence shown to the client. Neither is ever
 * "suspicious": a reason names the signal.
 */
final class DestinationAssessment
{
    /**
     * @param  array<string, mixed>  $signals
     */
    private function __construct(
        public readonly DestinationOutcome $outcome,
        public readonly ?string $reason = null,
        public readonly ?string $detail = null,
        public readonly array $signals = [],
    ) {}

    /**
     * @param  array<string, mixed>  $signals
     */
    public static function accept(array $signals = []): self
    {
        return new self(DestinationOutcome::Accept, null, null, $signals);
    }

    /**
     * @param  array<string, mixed>  $signals
     */
    public static function review(string $reason, array $signals = []): self
    {
        return new self(DestinationOutcome::Review, $reason, null, $signals);
    }

    /**
     * @param  array<string, mixed>  $signals
     */
    public static function block(string $reason, ?string $detail = null, array $signals = []): self
    {
        return new self(DestinationOutcome::Block, $reason, $detail, $signals);
    }

    public function isBlocking(): bool
    {
        return $this->outcome === DestinationOutcome::Block;
    }

    public function requiresReview(): bool
    {
        return $this->outcome === DestinationOutcome::Review;
    }
}
