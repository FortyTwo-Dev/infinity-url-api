<?php

declare(strict_types=1);

namespace Tests\Unit\Abuse;

use App\Services\Abuse\Scripts;
use PHPUnit\Framework\TestCase;

final class ScriptsTest extends TestCase
{
    public function test_it_detects_a_cyrillic_homoglyph(): void
    {
        // "lаposte.fr" with a CYRILLIC SMALL LETTER A.
        $this->assertTrue((new Scripts)->isMixed("l\u{0430}poste.fr"));
    }

    public function test_it_detects_a_punycode_homoglyph(): void
    {
        $ascii = idn_to_ascii("l\u{0430}poste.fr", IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);

        $this->assertIsString($ascii);
        $this->assertTrue((new Scripts)->isMixed($ascii));
    }

    public function test_a_plain_latin_domain_is_not_mixed(): void
    {
        $this->assertFalse((new Scripts)->isMixed('laposte.fr'));
        $this->assertFalse((new Scripts)->isMixed('münchen.de'));
    }

    public function test_a_japanese_label_is_one_script_group(): void
    {
        $this->assertFalse((new Scripts)->isMixed('日本語.jp'));
    }
}
