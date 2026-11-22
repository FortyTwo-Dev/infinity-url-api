<?php

declare(strict_types=1);

namespace App\Support\Analytics;

use Carbon\CarbonImmutable;

/**
 * The effective reporting window: requested bounds clamped to the retention
 * window, and to whole ISO weeks when the interval is weekly.
 */
final readonly class StatsRange
{
    public function __construct(
        public CarbonImmutable $from,
        public CarbonImmutable $to,
        public string $interval,
        public bool $clamped,
        public int $retentionDays,
        public bool $empty = false,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public static function resolve(
        array $input,
        CarbonImmutable $today,
        int $retentionDays,
        int $defaultWindowDays,
        int $maxBuckets,
    ): self {
        $today = $today->utc()->startOfDay();
        $interval = is_string($input['interval'] ?? null) ? $input['interval'] : 'day';

        $to = self::date($input['to'] ?? null) ?? $today;
        $from = self::date($input['from'] ?? null) ?? $to->subDays($defaultWindowDays - 1);

        $earliest = $today->subDays($retentionDays - 1);
        $clamped = false;

        if ($to->greaterThan($today)) {
            $to = $today;
            $clamped = true;
        }

        if ($from->lessThan($earliest)) {
            $from = $earliest;
            $clamped = true;
        }

        if ($to->lessThan($earliest)) {
            $from = $earliest;
            $to = $earliest;
            $clamped = true;
        }

        $capFrom = $to->subDays($maxBuckets - 1);

        if ($from->lessThan($capFrom)) {
            $from = $capFrom;
            $clamped = true;
        }

        if ($from->greaterThan($to)) {
            $from = $to;
            $clamped = true;
        }

        if ($interval === 'week') {
            [$from, $to, $weeklyClamped, $empty] = self::alignWeeks($from, $to, $earliest, $today);

            return new self($from, $to, $interval, $clamped || $weeklyClamped, $retentionDays, $empty);
        }

        return new self($from, $to, $interval, $clamped, $retentionDays);
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable, 2: bool, 3: bool}
     */
    private static function alignWeeks(
        CarbonImmutable $from,
        CarbonImmutable $to,
        CarbonImmutable $earliest,
        CarbonImmutable $today,
    ): array {
        $clamped = false;

        $weekStart = $from->startOfWeek(CarbonImmutable::MONDAY);

        // A week only counts when all its days are retained: half-purged
        // clicks next to whole-week uniques would not describe the same days.
        if ($weekStart->lessThan($earliest)) {
            $weekStart = $weekStart->addWeek();
            $clamped = true;
        }

        $weekEnd = $to->endOfWeek(CarbonImmutable::SUNDAY)->startOfDay();

        if ($weekEnd->greaterThan($today)) {
            $weekEnd = $today;
        }

        if (! $weekStart->equalTo($from) || ! $weekEnd->equalTo($to)) {
            $clamped = true;
        }

        // No whole ISO week fits the requested window (it sits inside the
        // partially retained week): serve an empty report rather than mixing a
        // single day of clicks with a whole week of uniques.
        if ($weekStart->greaterThan($weekEnd)) {
            return [$from, $to, true, true];
        }

        return [$weekStart, $weekEnd, $clamped, false];
    }

    private static function date(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        return CarbonImmutable::parse($value, 'UTC')->startOfDay();
    }
}
