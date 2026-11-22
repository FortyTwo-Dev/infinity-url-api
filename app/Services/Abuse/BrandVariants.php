<?php

declare(strict_types=1);

namespace App\Services\Abuse;

use App\Enums\LookalikeVariant;

/**
 * Deterministic lookalike generation (D-131).
 *
 * No model, no inference, no ranking: eight string transformations, applied to
 * the second-level label of a registrable brand domain. The result is stored
 * once and looked up with a single index read at creation time.
 *
 * The output is keyed by the ASCII form of the variant: a link pasted in
 * punycode and a link pasted in Unicode must resolve to the same row.
 */
final class BrandVariants
{
    /** Below three characters a label generates only noise. */
    private const MIN_LABEL_LENGTH = 3;

    private const ALPHABET = 'abcdefghijklmnopqrstuvwxyz';

    private const HOMOGLYPHS = [
        'a' => 'а', // CYRILLIC SMALL LETTER A
        'c' => 'с', // CYRILLIC SMALL LETTER ES
        'e' => 'е', // CYRILLIC SMALL LETTER IE
        'i' => 'і', // CYRILLIC SMALL LETTER BYELORUSSIAN-UKRAINIAN I
        'j' => 'ј', // CYRILLIC SMALL LETTER JE
        'o' => 'о', // CYRILLIC SMALL LETTER O
        'p' => 'р', // CYRILLIC SMALL LETTER ER
        's' => 'ѕ', // CYRILLIC SMALL LETTER DZE
        'x' => 'х', // CYRILLIC SMALL LETTER HA
        'y' => 'у', // CYRILLIC SMALL LETTER U
    ];

    private const TLDS = [
        'com', 'net', 'org', 'co', 'info', 'shop', 'xyz', 'online', 'site', 'live', 'top', 'app',
    ];

    private const COMBOSQUAT_KEYWORDS = [
        'secure', 'login', 'verify', 'account', 'support', 'service', 'moncompte', 'aide',
    ];

    /**
     * @param  array<string, true>  $protected  every protected domain, ASCII, to never shadow a real brand
     * @return array<string, LookalikeVariant>
     */
    public function variants(string $brandDomain, array $protected = []): array
    {
        $brandDomain = strtolower($brandDomain);
        [$label, $suffix] = $this->split($brandDomain);

        if ($label === null || $suffix === null || mb_strlen($label) < self::MIN_LABEL_LENGTH) {
            return [];
        }

        /** @var array<string, LookalikeVariant> $variants */
        $variants = [];
        $add = function (string $domain, LookalikeVariant $type) use (&$variants, $brandDomain, $protected): void {
            $ascii = $this->ascii($domain);

            if ($ascii === null || $ascii === $brandDomain || isset($protected[$ascii])) {
                return;
            }

            $variants[$ascii] ??= $type;
        };

        foreach ($this->substitutions($label) as $candidate) {
            $add($candidate.'.'.$suffix, LookalikeVariant::Substitution);
        }

        foreach ($this->deletions($label) as $candidate) {
            $add($candidate.'.'.$suffix, LookalikeVariant::Deletion);
        }

        foreach ($this->insertions($label) as $candidate) {
            $add($candidate.'.'.$suffix, LookalikeVariant::Insertion);
        }

        foreach ($this->transpositions($label) as $candidate) {
            $add($candidate.'.'.$suffix, LookalikeVariant::Transposition);
        }

        foreach ($this->homoglyphs($label) as $candidate) {
            $add($candidate.'.'.$suffix, LookalikeVariant::Homoglyph);
        }

        foreach (self::TLDS as $tld) {
            $add($label.'.'.$tld, LookalikeVariant::Tld);
        }

        foreach (self::COMBOSQUAT_KEYWORDS as $keyword) {
            $add($label.'-'.$keyword.'.'.$suffix, LookalikeVariant::Combosquat);
        }

        $add($label.$this->compactSuffix($suffix).'.com', LookalikeVariant::Concatenation);

        ksort($variants);

        return $variants;
    }

    /**
     * @return array{0: string|null, 1: string|null}
     */
    private function split(string $domain): array
    {
        $position = strpos($domain, '.');

        if ($position === false) {
            return [null, null];
        }

        $label = substr($domain, 0, $position);
        $suffix = substr($domain, $position + 1);

        return [$label, $suffix === '' ? null : $suffix];
    }

    private function compactSuffix(string $suffix): string
    {
        return str_replace('.', '', $suffix);
    }

    /**
     * @return list<string>
     */
    private function substitutions(string $label): array
    {
        $out = [];
        $length = mb_strlen($label);

        for ($index = 0; $index < $length; $index++) {
            $current = mb_substr($label, $index, 1);

            if (! ctype_alnum($current)) {
                continue;
            }

            for ($letter = 0; $letter < strlen(self::ALPHABET); $letter++) {
                $replacement = self::ALPHABET[$letter];

                if ($replacement === $current) {
                    continue;
                }

                $out[] = mb_substr($label, 0, $index).$replacement.mb_substr($label, $index + 1);
            }
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    private function deletions(string $label): array
    {
        $out = [];
        $length = mb_strlen($label);

        for ($index = 0; $index < $length; $index++) {
            $candidate = mb_substr($label, 0, $index).mb_substr($label, $index + 1);

            if ($candidate !== '') {
                $out[] = $candidate;
            }
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    private function insertions(string $label): array
    {
        $out = [];
        $length = mb_strlen($label);

        for ($index = 0; $index <= $length; $index++) {
            for ($letter = 0; $letter < strlen(self::ALPHABET); $letter++) {
                $out[] = mb_substr($label, 0, $index).self::ALPHABET[$letter].mb_substr($label, $index);
            }
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    private function transpositions(string $label): array
    {
        $out = [];
        $length = mb_strlen($label);

        for ($index = 0; $index < $length - 1; $index++) {
            $left = mb_substr($label, $index, 1);
            $right = mb_substr($label, $index + 1, 1);

            if ($left === $right) {
                continue;
            }

            $out[] = mb_substr($label, 0, $index)
                .$right.$left
                .mb_substr($label, $index + 2);
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    private function homoglyphs(string $label): array
    {
        $out = [];
        $length = mb_strlen($label);

        for ($index = 0; $index < $length; $index++) {
            $current = mb_substr($label, $index, 1);
            $replacement = self::HOMOGLYPHS[$current] ?? null;

            if ($replacement === null) {
                continue;
            }

            $out[] = mb_substr($label, 0, $index).$replacement.mb_substr($label, $index + 1);
        }

        return $out;
    }

    private function ascii(string $domain): ?string
    {
        $ascii = str_contains($domain, '.')
            ? idn_to_ascii($domain, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46)
            : false;

        $ascii = is_string($ascii) && $ascii !== '' ? strtolower($ascii) : null;
        $invalid = $ascii === null || array_filter(
            explode('.', $ascii),
            static fn (string $label): bool => $label === '' || strlen($label) > 63,
        ) !== [];

        return $invalid ? null : $ascii;
    }
}
