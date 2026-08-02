<?php

declare(strict_types=1);

namespace Diary\Support;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;
use JsonSerializable;
use Stringable;

/**
 * A calendar date with no time and no zone: what "entry date" and
 * "milestone date" mean in this application.
 *
 * Dates are the only cleartext detail the database keeps about sensitive
 * records, and the calendar, range queries and trend series are all keyed on
 * them, so they get a value object rather than raw strings.
 */
final class LocalDate implements Stringable, JsonSerializable
{
    private const SECONDS_PER_DAY = 86400;

    private function __construct(
        private readonly int $year,
        private readonly int $month,
        private readonly int $day,
    ) {
    }

    public static function of(int $year, int $month, int $day): self
    {
        if ($year < 1 || $year > 9999) {
            throw new InvalidArgumentException(sprintf('Year %d is outside the supported range 1-9999.', $year));
        }

        if (!checkdate($month, $day, $year)) {
            throw new InvalidArgumentException(sprintf('%04d-%02d-%02d is not a real date.', $year, $month, $day));
        }

        return new self($year, $month, $day);
    }

    public static function tryOf(int $year, int $month, int $day): ?self
    {
        if ($year < 1 || $year > 9999 || !checkdate($month, $day, $year)) {
            return null;
        }

        return new self($year, $month, $day);
    }

    /**
     * Parse a strict ISO-8601 calendar date, 'YYYY-MM-DD'.
     */
    public static function fromString(string $iso): self
    {
        $date = self::tryFromString($iso);

        if ($date === null) {
            throw new InvalidArgumentException(sprintf('Expected a date as YYYY-MM-DD, got "%s".', $iso));
        }

        return $date;
    }

    public static function tryFromString(string $iso): ?self
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $iso, $matches) !== 1) {
            return null;
        }

        return self::tryOf((int) $matches[1], (int) $matches[2], (int) $matches[3]);
    }

    public static function fromDateTime(DateTimeInterface $moment): self
    {
        return self::of(
            (int) $moment->format('Y'),
            (int) $moment->format('n'),
            (int) $moment->format('j')
        );
    }

    /**
     * Today according to the injected clock (UTC).
     */
    public static function today(Clock $clock): self
    {
        return self::fromDateTime($clock->now());
    }

    public static function fromEpochDay(int $epochDay): self
    {
        $moment = (new DateTimeImmutable('@' . ($epochDay * self::SECONDS_PER_DAY)))
            ->setTimezone(new DateTimeZone('UTC'));

        return self::fromDateTime($moment);
    }

    public function year(): int
    {
        return $this->year;
    }

    public function month(): int
    {
        return $this->month;
    }

    public function day(): int
    {
        return $this->day;
    }

    public function yearMonth(): YearMonth
    {
        return YearMonth::of($this->year, $this->month);
    }

    /**
     * ISO day of the week: 1 = Monday through 7 = Sunday. Used by the calendar
     * layout to place the first cell of a month.
     */
    public function dayOfWeek(): int
    {
        return (int) $this->toDateTimeImmutable()->format('N');
    }

    /**
     * Midnight UTC on this date, for binding to DATE/DATETIME columns.
     */
    public function toDateTimeImmutable(): DateTimeImmutable
    {
        $moment = DateTimeImmutable::createFromFormat(
            '!Y-m-d',
            $this->toIso(),
            new DateTimeZone('UTC')
        );

        // createFromFormat with a validated date cannot fail, but be explicit.
        if ($moment === false) {
            throw new InvalidArgumentException(sprintf('Could not build a date/time from "%s".', $this->toIso()));
        }

        return $moment;
    }

    /**
     * Days since 1970-01-01; negative before it. Cheap ordering and arithmetic.
     */
    public function toEpochDay(): int
    {
        return (int) floor($this->toDateTimeImmutable()->getTimestamp() / self::SECONDS_PER_DAY);
    }

    public function plusDays(int $days): self
    {
        if ($days === 0) {
            return $this;
        }

        return self::fromEpochDay($this->toEpochDay() + $days);
    }

    public function minusDays(int $days): self
    {
        return $this->plusDays(-$days);
    }

    public function compareTo(self $other): int
    {
        return $this->toIso() <=> $other->toIso();
    }

    public function equals(self $other): bool
    {
        return $this->year === $other->year
            && $this->month === $other->month
            && $this->day === $other->day;
    }

    public function isBefore(self $other): bool
    {
        return $this->compareTo($other) < 0;
    }

    public function isAfter(self $other): bool
    {
        return $this->compareTo($other) > 0;
    }

    public function isBeforeOrEqualTo(self $other): bool
    {
        return $this->compareTo($other) <= 0;
    }

    public function isAfterOrEqualTo(self $other): bool
    {
        return $this->compareTo($other) >= 0;
    }

    /**
     * 'YYYY-MM-DD'. Also the set/array key used wherever dates are collected.
     */
    public function toIso(): string
    {
        return sprintf('%04d-%02d-%02d', $this->year, $this->month, $this->day);
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
