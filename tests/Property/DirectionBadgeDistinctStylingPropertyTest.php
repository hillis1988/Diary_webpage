<?php

declare(strict_types=1);

namespace Diary\Tests\Property;

use Diary\Ai\CbtAdvice;
use Diary\Ai\ProgressSummary;
use Diary\Ai\SeriesStats;
use Diary\Ai\SummaryOutcome;
use Diary\Ai\TrendDirection;
use Diary\Ai\TrendMetrics;
use Diary\Diary\QuestionSet;
use Diary\Http\SummaryController;
use Diary\Support\DateRange;
use Diary\Support\LocalDate;
use Eris\Generator;
use Eris\TestTrait;
use PHPUnit\Framework\TestCase;

/**
 * Property 11: Each TrendDirection value renders with its own distinct
 * badge styling hook.
 *
 * For any two different TrendDirection values, the CSS class name rendered
 * on their respective direction badges differs.
 *
 * Each iteration generates one arbitrary non-empty SeriesStats shape
 * (count, min, max, mean) on the mood scale, then - deterministically, not
 * by chance - builds one such series per TrendDirection::cases() value,
 * renders each through SummaryController::render(), and extracts the exact
 * `badge--direction-*` class SummaryController put on that series' badge.
 * Iterating over every case on every run (rather than relying on Eris to
 * randomly sample all three cases across 100+ iterations) guarantees full
 * coverage of TrendDirection's cases regardless of how many iterations run.
 *
 * Requirements: 7.4.
 */
final class DirectionBadgeDistinctStylingPropertyTest extends TestCase
{
    use TestTrait;

    // Feature: summary-json-payload, Property 11: Each TrendDirection value renders with its own distinct badge styling hook
    public function testEachTrendDirectionValueRendersWithItsOwnDistinctBadgeStylingHook(): void
    {
        $this->limitTo(100)
            ->forAll(self::nonEmptySeriesShape(QuestionSet::MOOD_MIN, QuestionSet::MOOD_MAX))
            ->then(function (array $shape): void {
                [$count, $min, $max, $mean] = $shape;

                $classesByDirection = [];

                foreach (TrendDirection::cases() as $direction) {
                    $stats = SeriesStats::of($count, $mean, $min, $max, $direction);
                    $html = self::renderWithMoodSeries($stats);

                    $expectedClass = 'badge badge--direction-' . $direction->value;
                    self::assertStringContainsString(
                        'class="' . $expectedClass . '"',
                        $html,
                        "The {$direction->value} direction must render its own badge--direction-{$direction->value} class"
                    );

                    $classesByDirection[$direction->value] = $expectedClass;
                }

                // Every pair of distinct TrendDirection values must yield a distinct class string.
                foreach ($classesByDirection as $directionValueA => $classA) {
                    foreach ($classesByDirection as $directionValueB => $classB) {
                        if ($directionValueA === $directionValueB) {
                            continue;
                        }

                        self::assertNotSame(
                            $classA,
                            $classB,
                            "Directions {$directionValueA} and {$directionValueB} must not share a badge class"
                        );
                    }
                }

                // The classes collected must be as many distinct strings as there are cases.
                self::assertCount(
                    count(TrendDirection::cases()),
                    array_unique($classesByDirection),
                    'Each TrendDirection case must produce a distinct badge class string'
                );
            });
    }

    private static function renderWithMoodSeries(SeriesStats $mood): string
    {
        $sleep = SeriesStats::of(0, null, null, null, TrendDirection::Stable);
        $metrics = TrendMetrics::of($mood->count(), $mood, $sleep);
        $advice = new CbtAdvice('pattern', 'distortions', 'balanced perspective', 'next action');
        $summary = new ProgressSummary('A narrative about progress.', $advice, $metrics);
        $outcome = SummaryOutcome::summary($summary);

        $range = DateRange::of(LocalDate::of(2025, 3, 1), LocalDate::of(2025, 3, 31));

        return SummaryController::render($range, $outcome);
    }

    /**
     * A non-empty (count >= 1) series shape - count, min, max (ordered),
     * and a mean consistent with that min/max range - within
     * [$scaleMin, $scaleMax]. TrendDirection is deliberately excluded here:
     * the test assigns each of TrendDirection::cases() to this same shape
     * itself, deterministically, rather than generating a random direction.
     *
     * @return \Eris\Generator array{0: int, 1: int, 2: int, 3: float}
     */
    private static function nonEmptySeriesShape(int $scaleMin, int $scaleMax): \Eris\Generator
    {
        return Generator\map(
            static function (array $parts): array {
                [$count, $boundA, $boundB, $meanFraction] = $parts;
                $min = min($boundA, $boundB);
                $max = max($boundA, $boundB);
                $mean = $min + $meanFraction * ($max - $min);

                return [$count, $min, $max, $mean];
            },
            Generator\tuple(
                Generator\choose(1, 20),
                Generator\choose($scaleMin, $scaleMax),
                Generator\choose($scaleMin, $scaleMax),
                Generator\map(static fn (int $value): float => $value / 100, Generator\choose(0, 100))
            )
        );
    }
}
