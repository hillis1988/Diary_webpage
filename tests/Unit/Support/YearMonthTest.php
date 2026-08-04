<?php

declare(strict_types=1);

namespace Diary\Tests\Unit\Support;

use Diary\Support\FixedClock;
use Diary\Support\LocalDate;
use Diary\Support\YearMonth;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * The calendar navigates by month, so month length (leap years included) and
 * stepping over a year boundary have to be exact.
 */
final class YearMonthTest extends TestCase
{
    public function testMonthLengthCoversLeapAndCenturyRules(): void
    {
        self::assertSame(29, YearMonth::of(2024, 2)->lengthInDays(), '2024 is a leap year');
        self::assertSame(29, YearMonth::of(2000, 2)->lengthInDays(), '2000 is a leap year');
        self::assertSame(28, YearMonth::of(2023, 2)->lengthInDays());
        self::assertSame(28, YearMonth::of(1900, 2)->lengthInDays(), '1900 is not a leap year');
        self::assertSame(30, YearMonth::of(2024, 4)->lengthInDays());
        self::assertSame(31, YearMonth::of(2024, 12)->lengthInDays());
    }

    public function testFirstAndLastDayBoundTheMonth(): void
    {
        $february = YearMonth::of(2024, 2);

        self::assertSame('2024-02-01', $february->firstDay()->toIso());
        self::assertSame('2024-02-29', $february->lastDay()->toIso());
        self::assertSame('2024-02', $february->toIso());
        self::assertSame('2024-02', (string) $february);
        self::assertSame('2024-02', $february->jsonSerialize());
    }

    public function testAnOutOfRangeMonthOrYearIsRejected(): void
    {
        self::assertNull(YearMonth::tryOf(2024, 0));
        self::assertNull(YearMonth::tryOf(2024, 13));
        self::assertNull(YearMonth::tryOf(0, 1));
        self::assertNull(YearMonth::tryOf(10000, 1));

        $this->expectException(InvalidArgumentException::class);

        YearMonth::of(2024, 13);
    }

    public function testParsingAcceptsOnlyStrictYearMonth(): void
    {
        self::assertTrue(YearMonth::fromString('2024-02')->equals(YearMonth::of(2024, 2)));

        self::assertNull(YearMonth::tryFromString('2024-2'));
        self::assertNull(YearMonth::tryFromString('2024-02-01'));
        self::assertNull(YearMonth::tryFromString('2024-13'));

        $this->expectException(InvalidArgumentException::class);

        YearMonth::fromString('02/2024');
    }

    public function testSteppingMonthsCrossesYearBoundaries(): void
    {
        self::assertSame('2024-01', YearMonth::of(2023, 12)->next()->toIso());
        self::assertSame('2023-12', YearMonth::of(2024, 1)->previous()->toIso());
        self::assertSame('2025-03', YearMonth::of(2024, 1)->plusMonths(14)->toIso());
        self::assertSame('2022-12', YearMonth::of(2024, 1)->plusMonths(-13)->toIso());
        self::assertSame('2024-01', YearMonth::of(2024, 1)->plusMonths(0)->toIso());
    }

    public function testTwelveStepsForwardAndBackReturnsTheSameMonth(): void
    {
        $month = YearMonth::of(2024, 7);

        self::assertTrue($month->plusMonths(12)->plusMonths(-12)->equals($month));
    }

    public function testTheMonthAsARangeCoversEveryDayInclusively(): void
    {
        $range = YearMonth::of(2024, 2)->toDateRange();

        self::assertSame('2024-02-01', $range->start()->toIso());
        self::assertSame('2024-02-29', $range->end()->toIso());
        self::assertSame(29, $range->dayCount());
    }

    public function testContainsOnlyDatesInsideTheMonth(): void
    {
        $february = YearMonth::of(2024, 2);

        self::assertTrue($february->contains(LocalDate::of(2024, 2, 29)));
        self::assertFalse($february->contains(LocalDate::of(2024, 3, 1)));
        self::assertFalse($february->contains(LocalDate::of(2023, 2, 28)), 'same month, wrong year');
    }

    public function testCurrentAndFromLocalDateAgree(): void
    {
        $clock = FixedClock::at('2024-12-31 23:59:59');

        self::assertSame('2024-12', YearMonth::current($clock)->toIso());
        self::assertSame('2024-12', YearMonth::fromLocalDate(LocalDate::of(2024, 12, 31))->toIso());
        self::assertTrue(LocalDate::of(2024, 12, 31)->yearMonth()->equals(YearMonth::of(2024, 12)));
    }

    public function testComparisonOrdersChronologically(): void
    {
        self::assertTrue(YearMonth::of(2023, 12)->compareTo(YearMonth::of(2024, 1)) < 0);
        self::assertTrue(YearMonth::of(2024, 2)->compareTo(YearMonth::of(2024, 1)) > 0);
        self::assertSame(0, YearMonth::of(2024, 1)->compareTo(YearMonth::of(2024, 1)));
    }
}
