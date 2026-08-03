<?php

declare(strict_types=1);

namespace Diary\Tests\Unit\Http;

use Diary\Ai\ProgressSummary;
use Diary\Ai\SeriesStats;
use Diary\Ai\SummaryOutcome;
use Diary\Ai\TrendDirection;
use Diary\Ai\TrendMetrics;
use Diary\Http\FeedbackView;
use Diary\Http\SummaryController;
use Diary\Support\DateRange;
use Diary\Support\LocalDate;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Unit test for the forced-rendering-failure fallback (Requirement 6.6):
 * when rendering the `CBT_Notes_Card` throws, {@see SummaryController}
 * must render a fallback message in its place while still rendering the
 * trend metrics and the disclaimer.
 *
 * `ProgressSummary` and `CbtAdvice` are both `final`, closed value objects
 * with no seam for a conventional test double (they can't be subclassed,
 * and PHPUnit's mock generator refuses to double a `final` class), so the
 * failure double here is a `ProgressSummary` built via reflection with its
 * readonly `narrative` property left uninitialized. Calling `narrative()`
 * on it then throws PHP's own uninitialized-typed-property `Error` from
 * inside `renderCbtNotesCard()`, which is exactly the `\Throwable` that
 * `renderSummary()`'s `try`/`catch` is designed to catch - a genuine
 * rendering failure, not a simulated one, without touching production code.
 */
final class SummaryControllerNotesCardFallbackTest extends TestCase
{
    public function testRenderingFailureShowsFallbackMessageWithMetricsAndDisclaimerStillRendered(): void
    {
        $html = self::renderSummaryPageWithAFailingNotesCard();

        self::assertStringContainsString(
            htmlspecialchars("The AI CBT Therapist's notes could not be displayed.", ENT_QUOTES, 'UTF-8'),
            $html
        );
        self::assertStringContainsString('class="cbt-notes"', $html);
        self::assertStringContainsString(htmlspecialchars(FeedbackView::NOTES_HEADING, ENT_QUOTES, 'UTF-8'), $html);

        // The narrative and advice content must be absent - the real card never rendered.
        self::assertStringNotContainsString('cbt-notes__section', $html);

        // Trend metrics still render.
        self::assertStringContainsString('Entries considered', $html);
        self::assertStringContainsString('Mood rating', $html);
        self::assertStringContainsString('Sleep quality', $html);

        // The disclaimer still renders.
        self::assertStringContainsString('disclaimer', $html);
    }

    private static function renderSummaryPageWithAFailingNotesCard(): string
    {
        $summary = self::progressSummaryWithUninitializedNarrative();
        $outcome = SummaryOutcome::summary($summary);
        $range = DateRange::of(LocalDate::of(2025, 3, 1), LocalDate::of(2025, 3, 31));

        return SummaryController::render($range, $outcome);
    }

    /**
     * A {@see ProgressSummary} whose `metrics` field is populated (so
     * `renderMetrics()` still has real data to render after the fallback
     * kicks in) but whose readonly `narrative` field was never initialized,
     * so reading it throws.
     */
    private static function progressSummaryWithUninitializedNarrative(): ProgressSummary
    {
        $ref = new ReflectionClass(ProgressSummary::class);
        $summary = $ref->newInstanceWithoutConstructor();

        $mood = SeriesStats::of(5, 6.0, 3, 9, TrendDirection::Improving);
        $sleep = SeriesStats::of(5, 3.0, 2, 4, TrendDirection::Stable);
        $metrics = TrendMetrics::of(5, $mood, $sleep);

        $metricsProperty = $ref->getProperty('metrics');
        $metricsProperty->setAccessible(true);
        $metricsProperty->setValue($summary, $metrics);

        return $summary;
    }
}
