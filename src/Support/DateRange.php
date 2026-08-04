<?php

declare(strict_types=1);

namespace Diary\Support;

use Generator;
use Stringable;

/**
 * An inclusive range of calendar dates: both the start and the end date are
 * part of the range (Requirement 9.1).
 *
 * Two shapes need care, because the summary page lets a user pick any two dates
 * and property tests generate both deliberately:
 *
 * - Zero-length (start === end): a single day. It is inclusive, so it contains
 *   exactly one date, not none.
 * - Inverted (end before start): selects nothing. contains() is false for every
 *   date and dates() yields nothing, so an inverted selection can never pull in
 *   data the user did not ask for. Callers that want the tolerant reading can
 *   ask for normalised(); callers that want to reject it can ask isInverted().
 */
final class DateRange implements Stringable
{
    private function __construct(
        private readonly LocalDate $start,
        private readonly LocalDate $end,
    ) {
    }

    /**
     * Build a range exactly as given: the dates are not reordered.
     */
    public static function of(LocalDate $start, LocalDate $end): self
    {
        return new self($start, $end);
    }

    public static function singleDay(LocalDate $date): self
    {
        return new self($date, $date);
    }

    public function start(): LocalDate
    {
        return $this->start;
    }

    public function end(): LocalDate
    {
        return $this->end;
    }

    public function isInverted(): bool
    {
        return $this->end->isBefore($this->start);
    }

    /**
     * True only for an inverted range: a zero-length range still holds one day.
     */
    public function isEmpty(): bool
    {
        return $this->isInverted();
    }

    public function isSingleDay(): bool
    {
        return $this->start->equals($this->end);
    }

    public function contains(LocalDate $date): bool
    {
        if ($this->isInverted()) {
            return false;
        }

        return $date->isAfterOrEqualTo($this->start) && $date->isBeforeOrEqualTo($this->end);
    }

    /**
     * Number of dates in the range: 0 when inverted, 1 for a single day.
     */
    public function dayCount(): int
    {
        if ($this->isInverted()) {
            return 0;
        }

        return $this->end->toEpochDay() - $this->start->toEpochDay() + 1;
    }

    /**
     * Every date in the range, ascending. Yields nothing when inverted.
     *
     * @return Generator<int, LocalDate>
     */
    public function dates(): Generator
    {
        if ($this->isInverted()) {
            return;
        }

        $date = $this->start;
        $last = $this->end;

        while (true) {
            yield $date;

            if ($date->equals($last)) {
                return;
            }

            $date = $date->plusDays(1);
        }
    }

    /**
     * The same two endpoints in ascending order, so an inverted selection can
     * be read as the range the user probably meant.
     */
    public function normalised(): self
    {
        return $this->isInverted() ? new self($this->end, $this->start) : $this;
    }

    public function overlaps(self $other): bool
    {
        if ($this->isInverted() || $other->isInverted()) {
            return false;
        }

        return $this->start->isBeforeOrEqualTo($other->end)
            && $other->start->isBeforeOrEqualTo($this->end);
    }

    public function equals(self $other): bool
    {
        return $this->start->equals($other->start) && $this->end->equals($other->end);
    }

    public function __toString(): string
    {
        return $this->start->toIso() . '..' . $this->end->toIso();
    }
}
