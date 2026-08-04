<?php

declare(strict_types=1);

namespace Diary\Tests\Property;

use Diary\Ai\SeriesStats;
use Diary\Ai\SummaryEntryContent;
use Diary\Ai\SummaryMilestoneContent;
use Diary\Ai\SummaryPayload;
use Diary\Ai\TrendDirection;
use Diary\Ai\TrendMetrics;
use Diary\Milestone\MilestoneCategory;
use Diary\Support\DateRange;
use Diary\Support\LocalDate;
use Eris\Generator;
use Eris\TestTrait;
use PHPUnit\Framework\TestCase;

/**
 * Property 1: The Summary_Payload always carries all four required parts.
 *
 * For any SummaryInput (a date range, computed TrendMetrics, and any list of
 * entry and milestone content, including empty lists), the SummaryPayload
 * built from it exposes a date range, trend metrics for both mood and sleep,
 * an entries list, and a milestones list - every one of the four parts
 * present even when a list is empty.
 *
 * Requirements: 1.1, 1.5, 1.6.
 */
final class SummaryPayloadPropertyTest extends TestCase
{
    use TestTrait;

    // Feature: summary-json-payload, Property 1: The Summary_Payload always carries all four required parts
    public function testSummaryPayloadAlwaysCarriesAllFourRequiredParts(): void
    {
        $this->limitTo(100)
            ->forAll(
                self::dateRange(),
                self::trendMetrics(),
                self::entryList(),
                self::milestoneList()
            )
            ->then(function (
                DateRange $range,
                TrendMetrics $metrics,
                array $entries,
                array $milestones
            ): void {
                $payload = new SummaryPayload($range, $metrics, $entries, $milestones);
                $serialized = $payload->jsonSerialize();

                self::assertArrayHasKey('date_range', $serialized);
                self::assertSame($range->start()->toIso(), $serialized['date_range']['start']);
                self::assertSame($range->end()->toIso(), $serialized['date_range']['end']);

                self::assertArrayHasKey('trend_metrics', $serialized);
                self::assertArrayHasKey('mood', $serialized['trend_metrics']);
                self::assertArrayHasKey('sleep', $serialized['trend_metrics']);

                self::assertArrayHasKey('entries', $serialized);
                self::assertIsArray($serialized['entries']);
                self::assertCount(count($entries), $serialized['entries']);

                self::assertArrayHasKey('milestones', $serialized);
                self::assertIsArray($serialized['milestones']);
                self::assertCount(count($milestones), $serialized['milestones']);

                // The empty-list case must still yield an empty array, never an absent key.
                if ($entries === []) {
                    self::assertSame([], $serialized['entries']);
                }

                if ($milestones === []) {
                    self::assertSame([], $serialized['milestones']);
                }
            });
    }

    private static function dateRange(): \Eris\Generator
    {
        return Generator\map(
            static fn (array $dates): DateRange => DateRange::of($dates[0], $dates[1]),
            Generator\tuple(self::localDate(), self::localDate())
        );
    }

    private static function localDate(): \Eris\Generator
    {
        return Generator\map(
            static fn (array $parts): LocalDate => LocalDate::of($parts[0], $parts[1], $parts[2]),
            Generator\tuple(
                Generator\choose(2020, 2030),
                Generator\choose(1, 12),
                Generator\choose(1, 28)
            )
        );
    }

    private static function trendMetrics(): \Eris\Generator
    {
        return Generator\map(
            static fn (array $parts): TrendMetrics => TrendMetrics::of($parts[0], $parts[1], $parts[2]),
            Generator\tuple(
                Generator\choose(0, 30),
                self::seriesStats(),
                self::seriesStats()
            )
        );
    }

    /**
     * Either an empty series (count 0, mean/min/max null) or a non-empty
     * series with arbitrary count/mean/min/max/direction.
     */
    private static function seriesStats(): \Eris\Generator
    {
        return Generator\oneOf(
            Generator\constant(SeriesStats::of(0, null, null, null, TrendDirection::Stable)),
            Generator\map(
                static function (array $parts): SeriesStats {
                    [$count, $mean, $min, $max, $direction] = $parts;
                    $lower = min($min, $max);
                    $upper = max($min, $max);

                    return SeriesStats::of($count, $mean, $lower, $upper, $direction);
                },
                Generator\tuple(
                    Generator\choose(1, 30),
                    Generator\map(static fn (int $value): float => $value / 10, Generator\choose(-100, 100)),
                    Generator\choose(1, 10),
                    Generator\choose(1, 10),
                    Generator\elements(TrendDirection::cases())
                )
            )
        );
    }

    private static function entryList(): \Eris\Generator
    {
        return Generator\bind(
            Generator\choose(0, 5),
            static fn (int $length): \Eris\Generator => $length === 0
                ? Generator\constant([])
                : Generator\vector($length, self::entryContent())
        );
    }

    private static function entryContent(): \Eris\Generator
    {
        return Generator\map(
            static fn (array $parts): SummaryEntryContent => new SummaryEntryContent(
                $parts[0],
                $parts[1],
                $parts[2],
                $parts[3],
                $parts[4],
                $parts[5]
            ),
            Generator\tuple(
                self::localDate(),
                Generator\choose(1, 10),
                Generator\oneOf(Generator\constant(null), Generator\choose(1, 5)),
                self::freeText(),
                self::freeText(),
                self::freeText()
            )
        );
    }

    private static function milestoneList(): \Eris\Generator
    {
        return Generator\bind(
            Generator\choose(0, 5),
            static fn (int $length): \Eris\Generator => $length === 0
                ? Generator\constant([])
                : Generator\vector($length, self::milestoneContent())
        );
    }

    private static function milestoneContent(): \Eris\Generator
    {
        return Generator\map(
            static fn (array $parts): SummaryMilestoneContent => new SummaryMilestoneContent(
                $parts[0],
                $parts[1],
                $parts[2]
            ),
            Generator\tuple(
                self::localDate(),
                self::freeText(),
                Generator\elements(MilestoneCategory::cases())
            )
        );
    }

    /**
     * Arbitrary text including the empty string, exercising the
     * "empty string preserved, never omitted" edge case for record fields.
     */
    private static function freeText(): \Eris\Generator
    {
        return Generator\oneOf(
            Generator\constant(''),
            Generator\map(
                static fn (array $characters): string => implode('', $characters),
                Generator\vector(
                    10,
                    Generator\elements(str_split('abcdefghijklmnopqrstuvwxyz ., '))
                )
            )
        );
    }
}
