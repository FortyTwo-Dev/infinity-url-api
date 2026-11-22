<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Analytics\DedupKey;
use App\Support\Analytics\DedupWindow;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

/**
 * The correlation promise, pinned by known vectors: the same visitor on two
 * links must never produce the same key.
 */
final class DedupKeyTest extends TestCase
{
    private const SALT = 'unit-test-salt';

    private const IP = '203.0.113.42';

    private const DATE = '2026-11-21';

    private const LINK_ID = 7;

    private function keys(): DedupKey
    {
        return new DedupKey;
    }

    private function utc(string $value): CarbonImmutable
    {
        return CarbonImmutable::parse($value, 'UTC');
    }

    public function test_weekly_known_vector(): void
    {
        $this->assertSame(
            '56a97cf41eda56a47ffee8484823cf23cc388b2b736f82f3242f46486c30aa2d',
            $this->keys()->weekly(self::IP, self::LINK_ID, self::SALT),
        );
    }

    public function test_daily_known_vector(): void
    {
        $this->assertSame(
            '66dde51fe4d0a21f703370f66f39f15873e171b06e3110f016cb678e7c136926',
            $this->keys()->daily(self::IP, self::LINK_ID, self::DATE, self::SALT),
        );
    }

    public function test_weekly_known_vector_for_a_second_link(): void
    {
        $this->assertSame(
            '1658a261af34dffc1d381cd46cbdb63abcc1631b677eaa1769e7db5dace03509',
            $this->keys()->weekly(self::IP, 8, self::SALT),
        );
    }

    public function test_the_same_visitor_on_two_links_yields_different_keys(): void
    {
        $keys = $this->keys();

        $this->assertNotSame(
            $keys->weekly(self::IP, 7, self::SALT),
            $keys->weekly(self::IP, 8, self::SALT),
        );

        $this->assertNotSame(
            $keys->daily(self::IP, 7, self::DATE, self::SALT),
            $keys->daily(self::IP, 8, self::DATE, self::SALT),
        );
    }

    public function test_two_dates_change_the_daily_key_but_not_the_weekly_one(): void
    {
        $keys = $this->keys();

        $this->assertNotSame(
            $keys->daily(self::IP, self::LINK_ID, '2026-11-21', self::SALT),
            $keys->daily(self::IP, self::LINK_ID, '2026-11-22', self::SALT),
        );

        $this->assertSame(
            $keys->weekly(self::IP, self::LINK_ID, self::SALT),
            $keys->weekly(self::IP, self::LINK_ID, self::SALT),
        );
    }

    public function test_a_different_salt_changes_both_keys(): void
    {
        $keys = $this->keys();

        $this->assertNotSame(
            $keys->daily(self::IP, self::LINK_ID, self::DATE, self::SALT),
            $keys->daily(self::IP, self::LINK_ID, self::DATE, 'another-salt'),
        );

        $this->assertNotSame(
            $keys->weekly(self::IP, self::LINK_ID, self::SALT),
            $keys->weekly(self::IP, self::LINK_ID, 'another-salt'),
        );
    }

    public function test_keys_are_sixty_four_lowercase_hexadecimal_characters(): void
    {
        $keys = $this->keys();

        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{64}$/',
            $keys->daily(self::IP, self::LINK_ID, self::DATE, self::SALT),
        );

        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{64}$/',
            $keys->weekly(self::IP, self::LINK_ID, self::SALT),
        );
    }

    public function test_the_weekly_key_rotates_at_the_next_utc_monday(): void
    {
        $window = new DedupWindow;

        $sunday = $this->utc('2026-11-22 23:59:59');
        $mondayMidnight = $this->utc('2026-11-23 00:00:00');
        $mondayJustAfter = $this->utc('2026-11-23 00:00:01');
        $nextMonday = $this->utc('2026-11-30 00:00:00');

        $this->assertSame(
            '2026-11-23 00:00:00',
            $window->nextRotation($sunday)->utc()->format('Y-m-d H:i:s'),
        );

        $this->assertSame(
            '2026-11-30 00:00:00',
            $window->nextRotation($mondayMidnight)->utc()->format('Y-m-d H:i:s'),
        );

        $this->assertSame(
            $nextMonday->toIso8601ZuluString(),
            $window->nextRotation($mondayJustAfter)->utc()->toIso8601ZuluString(),
        );
    }

    public function test_the_daily_key_expires_at_the_end_of_the_utc_day(): void
    {
        $window = new DedupWindow;

        $end = $window->endOfDay($this->utc('2026-11-22 10:00:00'));

        $this->assertSame('2026-11-22T23:59:59.999999Z', $end->utc()->format('Y-m-d\TH:i:s.u\Z'));
    }
}
