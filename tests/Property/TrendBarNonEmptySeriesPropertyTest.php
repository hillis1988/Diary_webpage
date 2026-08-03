<?php

declare(strict_types=1);

namespace Diary\Tests\Property;

use Diary\Ai\CbtAdvice;
use Diary\Ai\ProgressSummary;
use Diary\Ai\SeriesStats;
use Diary\Ai\SummaryOutcome;
use Diary\Ai\TrendDirection;
use Diary\Ai\TrendMetrics;
use Diary\Ai\TrendPoint;
use Diary\Diary\QuestionSet;
use Diary\Http\SummaryController;
use Diary\Support\DateRange;
use Diary\Support\LocalDate;
use Eris\Generator;
use Eris\TestTrait;
use PHPUnit\Framework\TestCase;

/**
 * Property 10: A non-empty series' Trend_Line_Chart always renders its
 * line, mean reference, and direction badge.
 *
 * For any SeriesStats with count() >= 1 (any mean, minimum, maximum, and
 * dated points consistent with that count), the rendered Trend_Line_Chart
 * markup contains a line element, a point marker, a dashed mean-reference
 * line, and a direction-badge element, regardless of the specific values.
 *
 * Both the mood series (1-10 scale) and the sleep series (1-5 scale) are
 * generated non-empty, so the assertions expect two of each element - one
 * per series - within the page SummaryController::render() produces.
 *
 * Requirements: 7.2.
 */
final class TrendBarNonEmptySeriesPropertyTest extends TestCase
{
    use TestTrait;

    // Feature: summary-json-payload, Property 10: A non-empty series' Trend_Line_Chart always renders its line, mean reference, and direction badge
    public function testNonEmptySeriesTrendChartAlwaysRendersLineMeanReferenceAndDirectionBadge(): void
    {
        $this->limitTo(100)
            ->forAll(
                self::nonEmptySeriesStats(QuestionSet::MOOD_MIN, QuestionSet::MOOD_MAX),
                self::nonEmptySeriesStats(QuestionSet::SLEEP_MIN, QuestionSet::SLEEP_MAX)
            )
            ->then(function (SeriesStats $mood, SeriesStats $sleep): void {
                $metrics = TrendMetrics::of($mood->count() + $sleep->count(), $mood, $sleep);
                $advice = new CbtAdvice('pattern', 'distortions', 'balanced perspective', 'next action');
                $summary = new ProgressSummary('A narrative about progress.', $advice, $metrics);
                $outcome = SummaryOutcome::summary($summary);

                $range = DateRange::of(LocalDate::of(2025, 3, 1), LocalDate::of(2025, 3, 31));
                $html = SummaryController::render($range, $outcome);

                self::assertSame(
                    2,
                    substr_count($html, 'class="trend-chart__line"'),
                    'Both series must render a line element'
                );
                self::assertSame(
                    2,
                    substr_count($html, 'class="trend-chart__mean-line"'),
                    'Both series must render a mean-reference line element'
                );
                self::assertGreaterThanOrEqual(
                    2,
                    substr_count($html, 'class="trend-chart__point"'),
                    'Both series must render at least one point marker'
                );

                foreach ([$mood, $sleep] as $stats) {
                    self::assertStringContainsString(
                        'class="badge badge--direction-' . $stats->direction()->value . '"',
                        $html,
                        'Each series must render a direction badge matching its computed direction'
                    );
                }
            });
    }

    /**
     * A non-empty (count >= 1) SeriesStats generator for a series whose
     * values lie within [$scaleMin, $scaleMax]: count from 1 to 20, min and
     * max drawn from the scale and ordered, a mean consistent with that
     * min/max range, and a matching list of dated TrendPoints so the chart
     * has something to plot. The direction is arbitrary - the property
     * holds regardless of which direction was computed.
     */
    private static function nonEmptySeriesStats(int $scaleMin, int $scaleMax): \Eris\Generator
    {
        return Generator\map(
            static function (array $parts): SeriesStats {
                [$count, $boundA, $boundB, $meanFraction, $direction] = $parts;
                $min = min($boundA, $boundB);
                $max = max($boundA, $boundB);
                $mean = $min + $meanFraction * ($max - $min);

                $points = [];
                $date = LocalDate::of(2025, 3, 1);
                for ($i = 0; $i < $count; $i++) {
                    $value = $i % 2 === 0 ? $min : $max;
                    $points[] = new TrendPoint($date->plusDays($i), $value);
                }

                return SeriesStats::of($count, $mean, $min, $max, $direction, $points);
            },
            Generator\tuple(
                Generator\choose(1, 20),
                Generator\choose($scaleMin, $scaleMax),
                Generator\choose($scaleMin, $scaleMax),
                Generator\map(static fn (int $value): float => $value / 100, Generator\choose(0, 100)),
                Generator\elements(TrendDirection::cases())
            )
        );
    }
}
