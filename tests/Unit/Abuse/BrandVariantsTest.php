<?php

declare(strict_types=1);

namespace Tests\Unit\Abuse;

use App\Enums\LookalikeVariant;
use App\Services\Abuse\BrandVariants;
use PHPUnit\Framework\TestCase;

final class BrandVariantsTest extends TestCase
{
    /** @return array<string, LookalikeVariant> */
    private function variants(string $brand = 'laposte.fr', array $protected = []): array
    {
        return (new BrandVariants)->variants($brand, $protected);
    }

    public function test_it_generates_every_documented_variant(): void
    {
        $variants = $this->variants();

        $this->assertSame(LookalikeVariant::Substitution, $variants['leposte.fr'] ?? null);
        $this->assertSame(LookalikeVariant::Deletion, $variants['lapost.fr'] ?? null);
        $this->assertSame(LookalikeVariant::Insertion, $variants['lapostee.fr'] ?? null);
        $this->assertSame(LookalikeVariant::Transposition, $variants['lapotse.fr'] ?? null);
        $this->assertSame(LookalikeVariant::Tld, $variants['laposte.com'] ?? null);
        $this->assertSame(LookalikeVariant::Combosquat, $variants['laposte-secure.fr'] ?? null);
        $this->assertSame(LookalikeVariant::Concatenation, $variants['lapostefr.com'] ?? null);
    }

    public function test_it_generates_a_punycode_homoglyph(): void
    {
        $homoglyphs = array_filter(
            $this->variants(),
            static fn (LookalikeVariant $type): bool => $type === LookalikeVariant::Homoglyph,
        );

        $this->assertNotEmpty($homoglyphs);

        foreach (array_keys($homoglyphs) as $domain) {
            $this->assertStringStartsWith('xn--', $domain);
            $this->assertStringEndsWith('.fr', $domain);
        }
    }

    public function test_it_is_deterministic(): void
    {
        $this->assertSame($this->variants(), $this->variants());
    }

    public function test_it_never_returns_the_brand_itself(): void
    {
        $this->assertArrayNotHasKey('laposte.fr', $this->variants());
    }

    public function test_it_never_shadows_another_protected_brand(): void
    {
        $variants = $this->variants('laposte.fr', ['laposte.com' => true]);

        $this->assertArrayNotHasKey('laposte.com', $variants);
    }

    public function test_a_short_label_generates_nothing(): void
    {
        $this->assertSame([], $this->variants('e.leclerc'));
    }
}
