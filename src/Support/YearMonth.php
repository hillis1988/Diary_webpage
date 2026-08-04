<?php

declare(strict_types=1);

namespace Diary\Support;

use InvalidArgumentException;
use JsonSerializable;
use Stringable;

/**
 * A month in a year: the unit the calendar view navigates by.
 */
final class YearMonth implements Stringable, JsonSerializable
{
    private function __construct(
        private readonly int $year,
        private readonly int $month,
    ) {
    }

    public static function of(int $year, int $month): self
    {
        if ($year < 1 || $year > 9999) {
            throw new InvalidArgumentException(sprintf('Year %d is outside the supported range 1-9999.', $year));
        }

        if ($month < 1 || $month > 12) {
            throw new InvalidArgumentException(sprintf('Month %d is outside 1-12.', $month));
        }

        return new self($year, $month);
    }

    public static function tryOf(int $year, int $month): ?self
    {
        if ($year < 1 || $year > 9999 || $month < 1 || $month > 12) {
            return null;
        }

        return new self($year, $month);
    }

    /**
     * Parse 'YYYY-MM'.
     */
    public static function fromString(string $value): self
    {
        $month = self::tryFromString($value);

        if ($month === null) {
            throw new InvalidArgumentException(sprintf('Expected a month as YYYY-MM, got "%s".', $value));
        }

        return $month;
    }

    public static function tryFromString(string $value): ?self
    {
        if (preg_match('/^(\d{4})-(\d{2})$/', $value, $matches) !== 1) {
            return null;
        }

        return self::tryOf((int) $matches[1], (int) $matches[2]);
    }

    public static function fromLocalDate(LocalDate $date): self
    {
        return new self($date->year(), $date->month());
    }

    public static function current(Clock $clock): self
    {
        return self::fromLocalDate(LocalDate::today($clock));
    }

    public function year(): int
    {
        return $this->year;
    }

    public function month(): int
    {
        return $this->month;
    }

    /**
     * 28, 29, 30 or 31 - leap years included.
     */
    public function lengthInDays(): int
    {
        return (int) $this->firstDay()->toDateTimeImmutable()->format('t');
    }

    public function firstDay(): LocalDate
    {
        return LocalDate::of($this->year, $this->month, 1);
    }

    public function lastDay(): LocalDate
    {
        return LocalDate::of($this->year, $this->month, $this->lengthInDays());
    }

    /**
     * The whole month as an inclusive range.
     */
    public function toDateRange(): DateRange
    {
        return DateRange::of($this->firstDay(), $this->lastDay());
    }

    public function contains(LocalDate $date): bool
    {
        return $date->year() === $this->year && $date->month() === $this->month;
    }

    public function plusMonths(int $months): self
    {
        if ($months === 0) {
            return $this;
        }

        $zeroBased = ($this->year * 12 + ($this->month - 1)) + $months;

        return self::of(intdiv($zeroBased, 12), ($zeroBased % 12) + 1);
    }

    public function next(): self
    {
        return $this->plusMonths(1);
    }

    public function previous(): self
    {
        return $this->plusMonths(-1);
    }

    public function compareTo(self $other): int
    {
        return $this->toIso() <=> $other->toIso();
    }

    public function equals(self $other): bool
    {
        return $this->year === $other->year && $this->month === $other->month;
    }

    /**
     * 'YYYY-MM'.
     */
    public function toIso(): string
    {
        return sprintf('%04d-%02d', $this->year, $this->month);
    }

    public function __toString(): string
    {
        return $this->toIso();
    }

    public function jsonSerialize(): string
    {
        return $this->toIso();
    }
}
