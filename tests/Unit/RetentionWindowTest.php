<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Analytics\RetentionWindow;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class RetentionWindowTest extends TestCase
{
    private function window(): RetentionWindow
    {
        return new RetentionWindow;
    }

    private function utc(string $value): CarbonImmutable
    {
        return CarbonImmutable::parse($value, 'UTC');
    }

    public function test_the_purge_boundary_keeps_exactly_the_retention_window(): void
    {
        $window = $this->window();

        $this->assertSame('2026-10-24', $window->purgeBefore($this->utc('2026-11-22'), 30)->toDateString());
        $this->assertSame('2026-04-02', $window->purgeBefore($this->utc('2026-05-01'), 30)->toDateString());
        $this->assertSame('2028-02-01', $window->purgeBefore($this->utc('2028-03-01'), 30)->toDateString());
        $this->assertSame('2026-01-31', $window->purgeBefore($this->utc('2026-03-01'), 30)->toDateString());
    }

    public function test_a_one_day_window_keeps_today(): void
    {
        $window = $this->window();

        $this->assertSame(
            '2026-11-22',
            $window->purgeBefore($this->utc('2026-11-22'), 1)->toDateString(),
        );
    }

    public function test_a_zero_day_window_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->window()->purgeBefore($this->utc('2026-11-22'), 0);
    }

    public function test_the_weekly_cutoff_is_the_iso_week_of_the_boundary(): void
    {
        $cutoff = $this->window()->weekCutoff($this->utc('2026-10-24'));

        $this->assertSame(['iso_year' => 2026, 'iso_week' => 43], $cutoff);
    }
}
