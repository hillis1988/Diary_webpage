<?php

declare(strict_types=1);

namespace Diary\Tests\Unit\Ai;

use Diary\Ai\SeriesStats;
use Diary\Ai\TrendDirection;
use Diary\Ai\TrendPoint;
use Diary\Support\LocalDate;
use PHPUnit\Framework\TestCase;

/**
 * SeriesStats::points() round-trips the list passed into of(): the same
 * points, in the same order, come back out unchanged. The trailing $points
 * parameter defaults to an empty list, so every pre-existing positional
 * call site (constructed without a fifth/sixth argument) still compiles and
 * reports no points.
 */
final class SeriesStatsPointsTest extends TestCase
{
    public function testPointsRoundTripInOrder(): void
    {
        $points = [
            new TrendPoint(LocalDate::of(2025, 1, 1), 4),
            new TrendPoint(LocalDate::of(2025, 1, 2), 6),
            new TrendPoint(LocalDate::of(2025, 1, 3), 8),
        ];

        $stats = SeriesStats::of(3, 6.0, 4, 8, TrendDirection::Improving, $points);

        self::assertSame($points, $stats->points());
    }

    public function testPointsDefaultsToEmptyListWhenOmitted(): void
    {
        $stats = SeriesStats::of(3, 6.0, 4, 8, TrendDirection::Improving);

        self::assertSame([], $stats->points());
    }
}
