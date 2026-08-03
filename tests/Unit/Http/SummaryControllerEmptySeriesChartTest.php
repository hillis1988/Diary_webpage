<?php

declare(strict_types=1);

namespace Diary\Tests\Unit\Http;

use Diary\Ai\CbtAdvice;
use Diary\Ai\ProgressSummary;
use Diary\Ai\SeriesStats;
use Diary\Ai\SummaryOutcome;
use Diary\Ai\TrendDirection;
use Diary\Ai\TrendMetrics;
use Diary\Http\SummaryController;
use Diary\Support\DateRange;
use Diary\Support\LocalDate;
use PHPUnit\Framework\TestCase;

/**
 * An empty series (count() === 0) still renders no Trend_Line_Chart markup
 * at all - the same "no series data" short-circuit the old Trend_Bar had,
 * carried over to the new chart rendering.
 */
final class SummaryControllerEmptySeriesChartTest extends TestCase
{
    public function testEmptySeriesRendersNoChartMarkup(): void
    {
        $emptySeries = SeriesStats::of(0, null, null, null, TrendDirection::Stable);
        $metrics = TrendMetrics::of(0, $emptySeries, $emptySeries);
        $advice = new CbtAdvice('pattern', 'distortions', 'balanced perspective', 'next action');
        $summary = new ProgressSummary('A narrative about progress.', $advice, $metrics);
        $outcome = SummaryOutcome::summary($summary);

        $range = DateRange::of(LocalDate::of(2025, 3, 1), LocalDate::of(2025, 3, 31));
        $html = SummaryController::render($range, $outcome);

        self::assertStringNotContainsString('class="trend-chart"', $html);
        self::assertStringNotContainsString('class="trend-chart__line"', $html);
        self::assertStringNotContainsString('class="trend-chart__point"', $html);
        self::assertStringNotContainsString('class="trend-chart__mean-line"', $html);
        // The direction badge is part of the same short-circuited block, so
        // it must not render either for an empty series.
        self::assertStringNotContainsString('badge--direction-', $html);
    }
}
