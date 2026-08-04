<?php

declare(strict_types=1);

namespace Diary\Ai;

/**
 * Positions a value from its own native scale onto a shared 0-10 axis, so
 * metrics with different native scales (e.g. mood 1-10, sleep 1-5) can be
 * rendered against a single common axis (Requirement 8.1, 8.2).
 *
 * A pure function of a value and its native scale bounds - no HTTP- or
 * rendering-specific behaviour - so it stays directly unit/property-testable
 * and reusable by any future page needing the same shared axis.
 */
final class SharedTrendAxis
{
    private const AXIS_MIN = 0.0;
    private const AXIS_MAX = 10.0;

    /** A value's position on the shared 0-10 axis, given its own native scale. Clamped to [0, 10]. */
    public static function positionOf(int|float $value, int $nativeMin, int $nativeMax): float
    {
        $nativeSpan = $nativeMax - $nativeMin;
        if ($nativeSpan <= 0) {
            return self::AXIS_MIN;
        }

        $fraction = ($value - $nativeMin) / $nativeSpan;

        return max(self::AXIS_MIN, min(self::AXIS_MAX, $fraction * self::AXIS_MAX));
    }
}
