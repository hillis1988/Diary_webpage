<?php

declare(strict_types=1);

namespace Diary\Ai;

use Diary\Support\LocalDate;

/**
 * One dated data point for one series (mood rating or sleep quality) within
 * a {@see SeriesStats}: the entry date it came from, and that entry's value
 * on the series' own native scale.
 *
 * Immutable and closed to two fields deliberately - this is exactly the
 * shape {@see TrendCalculator} needs to carry forward per entry so the
 * summary page's Trend_Line_Chart can plot each value against its date,
 * nothing more.
 */
final class TrendPoint
{
    public function __construct(
        private readonly LocalDate $date,
        private readonly int $value,
    ) {
    }

    public function date(): LocalDate
    {
        return $this->date;
    }

    public function value(): int
    {
        return $this->value;
    }
}
