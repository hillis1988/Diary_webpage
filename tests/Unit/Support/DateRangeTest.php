<?php

declare(strict_types=1);

namespace Diary\Tests\Unit\Support;

use Diary\Support\DateRange;
use Diary\Support\LocalDate;
use PHPUnit\Framework\TestCase;

/**
 * The summary page lets a user pick any two dates, so a range has to be
 * inclusive at both ends and behave predictably when the two dates are equal or
 * the wrong way round (Requirement 9.1).
 */
final class DateRangeTest extends TestCase
{
    public function testBothEndpointsAreInsideTheRange(): void
    {
        $range = DateRange::of(LocalDate::of(2024, 2, 1), LocalDate::of(2024, 2, 29));

        self::assertTrue($range->contains(LocalDate::of(2024, 2, 1)), 'the start date is included');
        self::assertTrue($range->contains(LocalDate::of(2024, 2, 29)), 'the end date is included');
        self::assertTrue($range->contains(LocalDate::of(2024, 2, 15)));

        self::assertFalse($range->contains(LocalDate::of(2024, 1, 31)));
        self::assertFalse($range->contains(LocalDate::of(2024, 3, 1)));
    }

    public function testDayCountCountsBothEndpoints(): void
    {
        self::assertSame(
            29,
            DateRange::of(LocalDate::of(2024, 2, 1), LocalDate::of(2024, 2, 29))->dayCount(),
            'February 2024 is 29 days, leap day included'
        );

        self::assertSame(
            2,
            DateRange::of(LocalDate::of(2023, 12, 31), LocalDate::of(2024, 1, 1))->dayCount(),
            'a range across the year boundary holds both days'
        );
    }

    public function testAZeroLengthRangeHoldsExactlyOneDay(): void
    {
        $day = LocalDate::of(2024, 2, 29);
        $range = DateRange::of($day, $day);

        self::assertTrue($range->isSingleDay());
        self::assertFalse($range->isEmpty(), 'a single day is not an empty range');
        self::assertFalse($range->isInverted());
        self::assertSame(1, $range->dayCount());
        self::assertTrue($range->contains($day));
        self::assertSame(['2024-02-29'], self::isoDates($range));
        self::assertTrue($range->equals(DateRange::singleDay($day)));
    }

    public function testAnInvertedRangeSelectsNothing(): void
    {
        $start = LocalDate::of(2024, 3, 1);
        $end = LocalDate::of(2024, 2, 1);
        $range = DateRange::of($start, $end);

        self::assertTrue($range->isInverted());
        self::assertTrue($range->isEmpty());
        self::assertSame(0, $range->dayCount());
        self::assertSame([], self::isoDates($range), 'an inverted range yields no dates');

        self::assertFalse($range->contains($start), 'not even its own endpoints');
        self::assertFalse($range->contains($end));
        self::assertFalse($range->contains(LocalDate::of(2024, 2, 15)), 'nor anything between them');
    }

    public function testAnInvertedRangeKeepsItsEndpointsAsGivenUntilNormalised(): void
    {
        $range = DateRange::of(LocalDate::of(2024, 3, 1), LocalDate::of(2024, 2, 1));

        self::assertSame('2024-03-01', $range->start()->toIso(), 'the dates are not reordered on construction');
        self::assertSame('2024-02-01', $range->end()->toIso());

        $normalised = $range->normalised();

        self::assertFalse($normalised->isInverted());
        self::assertSame('2024-02-01', $normalised->start()->toIso());
        self::assertSame('2024-03-01', $normalised->end()->toIso());
        self::assertSame(30, $normalised->dayCount());
    }

    public function testNormalisingAnAscendingRangeChangesNothing(): void
    {
        $range = DateRange::of(LocalDate::of(2024, 2, 1), LocalDate::of(2024, 2, 29));

        self::assertTrue($range->normalised()->equals($range));
    }

    public function testDatesAreYieldedAscendingAcrossTheLeapDay(): void
    {
        $range = DateRange::of(LocalDate::of(2024, 2, 27), LocalDate::of(2024, 3, 1));

        self::assertSame(
            ['2024-02-27', '2024-02-28', '2024-02-29', '2024-03-01'],
            self::isoDates($range)
        );
        self::assertCount($range->dayCount(), self::isoDates($range));
    }

    public function testDatesCrossTheYearBoundaryInOrder(): void
    {
        $range = DateRange::of(LocalDate::of(2023, 12, 30), LocalDate::of(2024, 1, 2));

        self::assertSame(['2023-12-30', '2023-12-31', '2024-01-01', '2024-01-02'], self::isoDates($range));
    }

    public function testOverlapIncludesTouchingAtASingleDay(): void
    {
        $february = DateRange::of(LocalDate::of(2024, 2, 1), LocalDate::of(2024, 2, 29));

        self::assertTrue($february->overlaps(DateRange::of(LocalDate::of(2024, 2, 29), LocalDate::of(2024, 3, 5))));
        self::assertTrue($february->overlaps(DateRange::singleDay(LocalDate::of(2024, 2, 15))));
        self::assertFalse($february->overlaps(DateRange::of(LocalDate::of(2024, 3, 1), LocalDate::of(2024, 3, 5))));
    }

    public function testAnInvertedRangeOverlapsNothingInEitherDirection(): void
    {
        $inverted = DateRange::of(LocalDate::of(2024, 3, 1), LocalDate::of(2024, 2, 1));
        $february = DateRange::of(LocalDate::of(2024, 2, 1), LocalDate::of(2024, 2, 29));

        self::assertFalse($inverted->overlaps($february));
        self::assertFalse($february->overlaps($inverted));
    }

    public function testEqualityAndStringFormCompareEndpoints(): void
    {
        $range = DateRange::of(LocalDate::of(2024, 2, 1), LocalDate::of(2024, 2, 29));

        self::assertTrue($range->equals(DateRange::of(LocalDate::of(2024, 2, 1), LocalDate::of(2024, 2, 29))));
        self::assertFalse($range->equals(DateRange::of(LocalDate::of(2024, 2, 29), LocalDate::of(2024, 2, 1))));
        self::assertSame('2024-02-01..2024-02-29', (string) $range);
    }

    /**
     * @return list<string>
     */
    private static function isoDates(DateRange $range): array
    {
        $dates = [];

        foreach ($range->dates() as $date) {
            $dates[] = $date->toIso();
        }

        return $dates;
    }
}
