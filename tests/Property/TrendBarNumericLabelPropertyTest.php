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
 * Property 13: Trend_Line_Chart numeric labels always show the native-scale
 * value with its own metric's suffix, never the normalized axis position.
 *
 * For any generated mood series (native scale 1-10) and sleep series (native
 * scale 1-5), each with a concrete min/mean/max, {@see
 * SummaryController::render()}'s rendered HTML always contains the mood
 * series' min/mean/max formatted on its own 1-10 scale and suffixed "/10",
 * and the sleep series' min/mean/max formatted on its own 1-5 scale and
 * suffixed "/5" - the exact native value, never the value's position on the
 * shared 0-10 axis that only ever drives the chart's SVG coordinates.
 *
 * Requirements: 7.5, 8.3, 8.4.
 */
final class TrendBarNumericLabelPropertyTest extends TestCase
{
    use TestTrait;

    // Feature: summary-json-payload, Property 13: Trend_Line_Chart numeric labels always show the native-scale value with its own metric's suffix, never the normalized axis position
    public function testTrendBarNumericLabelsShowNativeScaleValueWithOwnSuffix(): void
    {
        $this->limitTo(100)
            ->forAll(
                self::nonEmptySeriesStats(QuestionSet::MOOD_MIN, QuestionSet::MOOD_MAX),
                self::nonEmptySeriesStats(QuestionSet::SLEEP_MIN, QuestionSet::SLEEP_MAX),
                Generator\choose(3, 50)
            )
            ->then(function (SeriesStats $mood, SeriesStats $sleep, int $entryCount): void {
                $metrics = TrendMetrics::of($entryCount, $mood, $sleep);
                $advice = new CbtAdvice('pattern', 'distortions', 'balanced perspective', 'next action');
                $summary = new ProgressSummary('A narrative about progress.', $advice, $metrics);
                $outcome = SummaryOutcome::summary($summary);

                $range = DateRange::of(LocalDate::of(2024, 1, 1), LocalDate::of(2024, 1, 31));

                $html = SummaryController::render($range, $outcome);

                self::assertMoodLabelPresent($html, $mood->min(), QuestionSet::MOOD_MAX);
                self::assertMoodLabelPresent($html, $mood->mean(), QuestionSet::MOOD_MAX);
                self::assertMoodLabelPresent($html, $mood->max(), QuestionSet::MOOD_MAX);

                self::assertSleepLabelPresent($html, $sleep->min(), QuestionSet::SLEEP_MAX);
                self::assertSleepLabelPresent($html, $sleep->mean(), QuestionSet::SLEEP_MAX);
                self::assertSleepLabelPresent($html, $sleep->max(), QuestionSet::SLEEP_MAX);
            });
    }

    /**
     * Asserts the exact "<formatted value>/<scaleMax>" substring is present
     * (never the shared 0-10 axis position, and never the other metric's
     * scale suffix).
     */
    private static function assertMoodLabelPresent(string $html, int|float|null $value, int $scaleMax): void
    {
        self::assertNotNull($value, 'a non-empty series never carries a null min/mean/max');

        $expected = sprintf('%s/%d', self::formatNumber($value), $scaleMax);

        self::assertStringContainsString(
            $expected,
            $html,
            sprintf('expected the mood series\' native-scale label "%s" in the rendered HTML', $expected)
        );
    }

    private static function assertSleepLabelPresent(string $html, int|float|null $value, int $scaleMax): void
    {
        self::assertNotNull($value, 'a non-empty series never carries a null min/mean/max');

        $expected = sprintf('%s/%d', self::formatNumber($value), $scaleMax);

        self::assertStringContainsString(
            $expected,
            $html,
            sprintf('expected the sleep series\' native-scale label "%s" in the rendered HTML', $expected)
        );
    }

    /**
     * Mirrors SummaryController::formatNullableNumber()'s rule: an integer
     * (min/max) is rendered plain, a float (mean) always with one decimal -
     * even when it is a whole number - since {@see SeriesStats::mean()} is
     * typed float and PHP's is_float() is true regardless of the numeric
     * value.
     */
    private static function formatNumber(int|float $value): string
    {
        return is_float($value) ? number_format($value, 1) : (string) $value;
    }

    /**
     * A non-empty SeriesStats (count > 0, concrete min/mean/max) with min
     * and max drawn independently from the native scale (so they may
     * coincide) and a mean placed at an arbitrary fraction between them,
     * always as a genuine float per SeriesStats::mean()'s type.
     */
    private static function nonEmptySeriesStats(int $scaleMin, int $scaleMax): \Eris\Generator
    {
        return Generator\map(
            static function (array $parts): SeriesStats {
                [$count, $a, $b, $meanFraction, $direction] = $parts;
                $min = min($a, $b);
                $max = max($a, $b);
                $mean = (float) ($min + ($max - $min) * ($meanFraction / 100));

                return SeriesStats::of($count, $mean, $min, $max, $direction);
            },
            Generator\tuple(
                Generator\choose(1, 20),
                Generator\choose($scaleMin, $scaleMax),
                Generator\choose($scaleMin, $scaleMax),
                Generator\choose(0, 100),
                Generator\elements(TrendDirection::cases())
            )
        );
    }
}
