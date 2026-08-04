<?php

declare(strict_types=1);

namespace Diary\Tests\Property;

use Diary\Ai\SeriesStats;
use Diary\Ai\SummaryEntryContent;
use Diary\Ai\SummaryInput;
use Diary\Ai\SummaryMilestoneContent;
use Diary\Ai\SummaryPromptBuilder;
use Diary\Ai\TrendDirection;
use Diary\Ai\TrendMetrics;
use Diary\Milestone\MilestoneCategory;
use Diary\Support\DateRange;
use Diary\Support\LocalDate;
use Eris\Generator;
use Eris\TestTrait;
use PHPUnit\Framework\TestCase;

/**
 * Property 3: Entries and milestones are ordered chronologically, stable on
 * ties.
 *
 * For any list of entry content and any list of milestone content supplied
 * to SummaryPromptBuilder::buildPayload() in an arbitrary (including
 * reverse or shuffled) input order, and possibly containing multiple
 * records sharing the same date, the built SummaryPayload's entries and
 * milestones lists are non-decreasing by date, and any two records sharing
 * a date appear in the same relative order they had in the input.
 *
 * Each generated record is stamped with a marker holding its own position
 * in the input array (before buildPayload() sorts it) - "seq-{index}" in
 * the entry's `events` field, or the milestone's `description` field. Only
 * three distinct dates are drawn per test case and 5-8 records are
 * generated against them, so ties are all but guaranteed regardless of
 * generated input order.
 *
 * Requirements: 1.4.
 */
final class SummaryChronologicalOrderingPropertyTest extends TestCase
{
    use TestTrait;

    // Feature: summary-json-payload, Property 3: Entries and milestones are ordered chronologically, stable on ties
    public function testEntriesAndMilestonesAreOrderedChronologicallyStableOnTies(): void
    {
        $this->limitTo(100)
            ->forAll(
                self::dateRange(),
                self::trendMetrics(),
                self::groupDates(),
                self::recordSpecList(),
                self::recordSpecList()
            )
            ->then(function (
                DateRange $range,
                TrendMetrics $metrics,
                array $groupDates,
                array $entrySpecs,
                array $milestoneSpecs
            ): void {
                $entries = [];
                foreach ($entrySpecs as $index => $spec) {
                    [$groupIndex, $moodRating, $sleepQuality, $thoughts, $emotions] = $spec;
                    $entries[] = new SummaryEntryContent(
                        $groupDates[$groupIndex],
                        $moodRating,
                        $sleepQuality,
                        "seq-{$index}",
                        $thoughts,
                        $emotions
                    );
                }

                $milestones = [];
                foreach ($milestoneSpecs as $index => $spec) {
                    [$groupIndex, , , , , $category] = $spec;
                    $milestones[] = new SummaryMilestoneContent(
                        $groupDates[$groupIndex],
                        "seq-{$index}",
                        $category
                    );
                }

                $input = new SummaryInput($range, $metrics, $entries, $milestones);
                $payload = (new SummaryPromptBuilder())->buildPayload($input);
                $serialized = $payload->jsonSerialize();

                self::assertChronologicalAndStable(
                    $serialized['entries'],
                    static fn (SummaryEntryContent $entry): LocalDate => $entry->date(),
                    static fn (SummaryEntryContent $entry): string => $entry->events()
                );

                self::assertChronologicalAndStable(
                    $serialized['milestones'],
                    static fn (SummaryMilestoneContent $milestone): LocalDate => $milestone->date(),
                    static fn (SummaryMilestoneContent $milestone): string => $milestone->description()
                );
            });
    }

    /**
     * Asserts a sorted record list is non-decreasing by date, and that any
     * two records sharing a date appear in the same relative order they had
     * in the input - recoverable from the "seq-{index}" marker each record
     * carries. Because the list is sorted ascending, records sharing a date
     * are always contiguous, so checking each adjacent pair suffices to
     * cover every same-date pair in the list.
     *
     * @param list<mixed> $records
     * @param callable(mixed): LocalDate $dateOf
     * @param callable(mixed): string $markerOf
     */
    private static function assertChronologicalAndStable(array $records, callable $dateOf, callable $markerOf): void
    {
        for ($i = 1; $i < count($records); $i++) {
            $previous = $records[$i - 1];
            $current = $records[$i];

            $previousDate = $dateOf($previous);
            $currentDate = $dateOf($current);

            self::assertTrue(
                $previousDate->isBeforeOrEqualTo($currentDate),
                "Record at position {$i} (date {$currentDate->toIso()}) is out of order "
                    . "after record at position " . ($i - 1) . " (date {$previousDate->toIso()})."
            );

            if ($previousDate->equals($currentDate)) {
                $previousMarker = self::markerIndex($markerOf($previous));
                $currentMarker = self::markerIndex($markerOf($current));

                self::assertLessThan(
                    $currentMarker,
                    $previousMarker,
                    "Records sharing date {$currentDate->toIso()} were reordered relative to their input order "
                        . "(marker {$previousMarker} ended up after marker {$currentMarker})."
                );
            }
        }
    }

    private static function markerIndex(string $marker): int
    {
        return (int) substr($marker, strlen('seq-'));
    }

    /**
     * Three dates, drawn independently, that group indices are chosen
     * against - duplicates among the three are possible and harmless.
     *
     * @return \Eris\Generator
     */
    private static function groupDates(): \Eris\Generator
    {
        return Generator\vector(3, self::localDate());
    }

    /**
     * A list of 5-8 record specs: (group index into groupDates(), mood
     * rating, sleep quality, thoughts, emotions/category-filler). Milestone
     * construction only uses the group index and the category slot; entry
     * construction uses all five. Generated in whatever order Eris produces
     * them in - that order is itself the "arbitrary input order" the
     * property is about.
     *
     * @return \Eris\Generator
     */
    private static function recordSpecList(): \Eris\Generator
    {
        return Generator\bind(
            Generator\choose(5, 8),
            static fn (int $length): \Eris\Generator => Generator\vector($length, self::recordSpec())
        );
    }

    private static function recordSpec(): \Eris\Generator
    {
        return Generator\tuple(
            Generator\choose(0, 2),
            Generator\choose(1, 10),
            Generator\oneOf(Generator\constant(null), Generator\choose(1, 5)),
            self::freeText(),
            self::freeText(),
            Generator\elements(MilestoneCategory::cases())
        );
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

    /**
     * Arbitrary text including the empty string, used for thoughts/emotions
     * filler that plays no role in the ordering assertions.
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
