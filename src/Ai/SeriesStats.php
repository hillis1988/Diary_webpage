<?php

declare(strict_types=1);

namespace Diary\Ai;

/**
 * Deterministic statistics for one numeric series (mood rating or sleep
 * quality) across a date range: count, mean, minimum, maximum, and a
 * direction derived from a least-squares slope (Requirement 9.2).
 *
 * An empty series (count = 0, for example a sleep series where every entry
 * left the optional question blank) carries no mean, minimum or maximum -
 * there is nothing to average or bound - and defaults to {@see
 * TrendDirection::Stable} as the safe "nothing observed" reading, since
 * neither Improving nor Declining can be justified from zero data points.
 */
final class SeriesStats
{
    private function __construct(
        private readonly int $count,
        private readonly ?float $mean,
        private readonly ?int $min,
        private readonly ?int $max,
        private readonly TrendDirection $direction,
        private readonly array $points,
    ) {
    }

    /**
     * @param list<TrendPoint> $points chronologically ordered, one per
     *     contributing entry; defaults to an empty list so every existing
     *     positional call site continues to compile unchanged
     */
    public static function of(
        int $count,
        ?float $mean,
        ?int $min,
        ?int $max,
        TrendDirection $direction,
        array $points = [],
    ): self {
        return new self($count, $mean, $min, $max, $direction, $points);
    }

    /** How many values are in the series. */
    public function count(): int
    {
        return $this->count;
    }

    /** Null only when count() is 0. */
    public function mean(): ?float
    {
        return $this->mean;
    }

    /** Null only when count() is 0. */
    public function min(): ?int
    {
        return $this->min;
    }

    /** Null only when count() is 0. */
    public function max(): ?int
    {
        return $this->max;
    }

    public function direction(): TrendDirection
    {
        return $this->direction;
    }

    /**
     * The dated data points this series was computed from, chronologically
     * ordered. Empty for a series built without points (including every
     * call site predating {@see TrendPoint}) and for an empty series
     * (count() === 0).
     *
     * @return list<TrendPoint>
     */
    public function points(): array
    {
        return $this->points;
    }
}
