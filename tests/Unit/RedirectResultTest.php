<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\RedirectResult;
use LogicException;
use PHPUnit\Framework\TestCase;

final class RedirectResultTest extends TestCase
{
    private const DESTINATION = 'https://example.com';

    public function test_a_redirect_is_valid_and_serializes(): void
    {
        $result = RedirectResult::redirect(self::DESTINATION, null, 42);

        $this->assertTrue($result->isValid());
        $this->assertSame(self::DESTINATION, $result->locationOrFail());

        $array = $result->toArray();

        $this->assertSame(302, $array['status']);
        $this->assertSame(self::DESTINATION, $array['location']);
        $this->assertSame(42, $array['link_id']);
    }

    public function test_a_redirect_without_a_link_id_is_invalid(): void
    {
        $result = RedirectResult::tryFromArray(['status' => 302, 'location' => self::DESTINATION]);

        $this->assertNotNull($result);
        $this->assertFalse($result->isValid());
    }

    public function test_a_redirect_without_a_location_fails(): void
    {
        $result = RedirectResult::tryFromArray(['status' => 302, 'location' => null]);

        $this->assertNotNull($result);
        $this->expectException(LogicException::class);

        $result->locationOrFail();
    }

    public function test_terminal_states_are_valid_only_for_a_known_reason(): void
    {
        $this->assertTrue(RedirectResult::tryFromArray(['status' => 410, 'reason' => 'deleted'])->isValid());
        $this->assertTrue(RedirectResult::tryFromArray(['status' => 410, 'reason' => 'expired'])->isValid());
        $this->assertFalse(RedirectResult::tryFromArray(['status' => 410, 'reason' => 'other'])->isValid());

        $this->assertTrue(RedirectResult::tryFromArray(['status' => 403])->isValid());
        $this->assertTrue(RedirectResult::tryFromArray(['status' => 404])->isValid());
        $this->assertFalse(RedirectResult::tryFromArray(['status' => 500])->isValid());
    }
}
