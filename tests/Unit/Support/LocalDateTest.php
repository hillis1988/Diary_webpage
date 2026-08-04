<?php

declare(strict_types=1);

namespace Diary\Tests\Unit\Support;

use Diary\Support\FixedClock;
use Diary\Support\LocalDate;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Entry dates and milestone dates are the only cleartext detail the database
 * keeps about sensitive records, and every range query, calendar cell and trend
 * series is keyed on them, so the awkward cases (leap days, month and year
 * boundaries, loose input) are pinned here (Requirement 9.1).
 */
final class LocalDateTest extends TestCase
{
    public function testAValidDateExposesItsParts(): void
    {
        $date = LocalDate::of(2024, 2, 29);

        self::assertSame(2024, $date->year());
        self::assertSame(2, $date->month());
        self::assertSame(29, $date->day());
        self::assertSame('2024-02-29', $date->toIso());
        self::assertSame('2024-02-29', (string) $date);
        self::assertSame('2024-02-29', $date->jsonSerialize());
    }

    public function testLeapDayExistsOnlyInLeapYears(): void
    {
        self::assertNotNull(LocalDate::tryOf(2024, 2, 29), '2024 is a leap year');
        self::assertNotNull(LocalDate::tryOf(2000, 2, 29), '2000 is a leap year: divisible by 400');

        self::assertNull(LocalDate::tryOf(2023, 2, 29), '2023 is not a leap year');
        self::assertNull(LocalDate::tryOf(1900, 2, 29), '1900 is not a leap year: divisible by 100, not 400');
    }

    public function testAnImpossibleDateIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        LocalDate::of(2023, 2, 29);
    }

    public function testAYearOutsideTheSupportedRangeIsRejected(): void
    {
        self::assertNull(LocalDate::tryOf(0, 1, 1));
        self::assertNull(LocalDate::tryOf(10000, 1, 1));

        $this->expectException(InvalidArgumentException::class);

        LocalDate::of(10000, 1, 1);
    }

    public function testParsingAcceptsOnlyStrictIsoDates(): void
    {
        self::assertTrue(LocalDate::fromString('2024-02-29')->equals(LocalDate::of(2024, 2, 29)));

        self::assertNull(LocalDate::tryFromString('2024-2-9'), 'unpadded parts are not ISO');
        self::assertNull(LocalDate::tryFromString('2024-02-30'), 'February has no 30th');
        self::assertNull(LocalDate::tryFromString('2024-13-01'), 'there is no thirteenth month');
        self::assertNull(LocalDate::tryFromString('2024-02-29 00:00:00'), 'a date carries no time');
        self::assertNull(LocalDate::tryFromString(''));

        $this->expectException(InvalidArgumentException::class);

        LocalDate::fromString('29/02/2024');
    }

    public function testAddingADayCrossesMonthAndYearBoundaries(): void
    {
        self::assertSame('2024-02-29', LocalDate::of(2024, 2, 28)->plusDays(1)->toIso());
        self::assertSame('2024-03-01', LocalDate::of(2024, 2, 29)->plusDays(1)->toIso());
        self::assertSame('2023-03-01', LocalDate::of(2023, 2, 28)->plusDays(1)->toIso());
        self::assertSame('2024-01-01', LocalDate::of(2023, 12, 31)->plusDays(1)->toIso());
        self::assertSame('2023-12-31', LocalDate::of(2024, 1, 1)->minusDays(1)->toIso());
        self::assertSame('2024-02-29', LocalDate::of(2024, 3, 1)->minusDays(1)->toIso());
    }

    public function testAYearOfDaysLandsOnTheSameDateInANonLeapYear(): void
    {
        self::assertSame('2024-01-01', LocalDate::of(2023, 1, 1)->plusDays(365)->toIso());
        self::assertSame('2025-01-01', LocalDate::of(2024, 1, 1)->plusDays(366)->toIso(), '2024 has 366 days');
    }

    public function testAddingZeroDaysReturnsTheSameDate(): void
    {
        $date = LocalDate::of(2024, 6, 15);

        self::assertTrue($date->plusDays(0)->equals($date));
    }

    public function testEpochDayRoundTripsAcrossTheEpochItself(): void
    {
        self::assertSame(0, LocalDate::of(1970, 1, 1)->toEpochDay());
        self::assertSame(-1, LocalDate::of(1969, 12, 31)->toEpochDay());

        foreach (['1969-07-20', '1970-01-01', '2024-02-29', '2099-12-31'] as $iso) {
            $date = LocalDate::fromString($iso);

            self::assertSame($iso, LocalDate::fromEpochDay($date->toEpochDay())->toIso());
        }
    }

    public function testDayOfWeekIsIsoNumbered(): void
    {
        self::assertSame(1, LocalDate::of(2024, 1, 1)->dayOfWeek(), '2024-01-01 was a Monday');
        self::assertSame(7, LocalDate::of(2024, 1, 7)->dayOfWeek(), '2024-01-07 was a Sunday');
        self::assertSame(4, LocalDate::of(2024, 2, 29)->dayOfWeek(), '2024-02-29 was a Thursday');
    }

    public function testComparisonOrdersChronologically(): void
    {
        $earlier = LocalDate::of(2024, 2, 28);
        $later = LocalDate::of(2024, 3, 1);

        self::assertTrue($earlier->isBefore($later));
        self::assertTrue($later->isAfter($earlier));
        self::assertFalse($earlier->isAfter($later));
        self::assertSame(-1, $earlier->compareTo($later) <=> 0);
        self::assertSame(0, $earlier->compareTo(LocalDate::of(2024, 2, 28)));

        self::assertTrue($earlier->isBeforeOrEqualTo($earlier));
        self::assertTrue($earlier->isAfterOrEqualTo($earlier));
    }

    public function testTodayAndFromDateTimeReadTheClockInUtc(): void
    {
        $clock = FixedClock::at('2024-02-29 23:30:00');

        self::assertSame('2024-02-29', LocalDate::today($clock)->toIso());
        self::assertSame('2024-02-29', LocalDate::fromDateTime($clock->now())->toIso());
    }

    public function testMidnightUtcIsUsedWhenBindingToTheDatabase(): void
    {
        $moment = LocalDate::of(2024, 2, 29)->toDateTimeImmutable();

        self::assertSame('2024-02-29 00:00:00', $moment->format('Y-m-d H:i:s'));
        self::assertSame('UTC', $moment->getTimezone()->getName());
    }
}
