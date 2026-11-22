<?php

declare(strict_types=1);

namespace App\Services\Abuse;

use App\Enums\LookalikeVariant;

/**
 * The brand signal behind a destination, with the reason that will be quoted
 * to the link owner. A reason is always factual: "one letter away from
 * laposte.fr", never "suspicious domain".
 */
final class BrandMatch
{
    public const TYPOSQUAT = 'typosquat';

    public const SUBDOMAIN = 'brand_subdomain';

    public const MIXED_SCRIPTS = 'mixed_scripts';

    public function __construct(
        public readonly string $kind,
        public readonly ?string $brand = null,
        public readonly ?LookalikeVariant $variant = null,
    ) {}

    public static function typosquat(string $brand, LookalikeVariant $variant): self
    {
        return new self(self::TYPOSQUAT, $brand, $variant);
    }

    public static function subdomain(string $brand): self
    {
        return new self(self::SUBDOMAIN, $brand);
    }

    public static function mixedScripts(): self
    {
        return new self(self::MIXED_SCRIPTS);
    }

    public function isBlocking(): bool
    {
        return $this->kind === self::SUBDOMAIN
            || $this->kind === self::MIXED_SCRIPTS
            || $this->variant === LookalikeVariant::Homoglyph;
    }

    /**
     * Compact machine reason stored on the link and read by the operator.
     */
    public function reason(): string
    {
        return match ($this->kind) {
            self::TYPOSQUAT => 'typosquat:'.($this->variant instanceof LookalikeVariant ? $this->variant->value : 'unknown').':'.($this->brand ?? ''),
            self::SUBDOMAIN => 'brand_subdomain:'.($this->brand ?? ''),
            default => 'mixed_scripts',
        };
    }
}
