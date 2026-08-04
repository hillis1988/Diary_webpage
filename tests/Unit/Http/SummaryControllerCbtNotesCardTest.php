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
 * Unit test for the "AI CBT Therapist's notes" card's reused CSS classes
 * (Requirement 6.3): the summary page's card must reuse the same
 * `cbt-notes*` class names {@see \Diary\Http\FeedbackView::renderNotes()}
 * already uses for a recommendation, so the two AI surfaces look identical
 * apart from their content.
 *
 * Builds a {@see ProgressSummary} directly and renders it through
 * {@see SummaryController::render()} - the same lightweight, DB-free entry
 * point the property tests in tests/Property/ use - rather than going
 * through the full controller/DB request cycle.
 */
final class SummaryControllerCbtNotesCardTest extends TestCase
{
    public function testRenderedCardMarkupContainsReusedCbtNotesCssClasses(): void
    {
        $html = self::renderSummaryPage();

        self::assertStringContainsString('class="cbt-notes"', $html);
        self::assertStringContainsString('class="cbt-notes__divider"', $html);
        self::assertStringContainsString('class="cbt-notes__heading"', $html);
        self::assertStringContainsString('class="cbt-notes__section"', $html);
        self::assertStringContainsString('class="cbt-notes__label"', $html);
    }

    private static function renderSummaryPage(): string
    {
        $mood = SeriesStats::of(5, 6.0, 3, 9, TrendDirection::Improving);
        $sleep = SeriesStats::of(5, 3.0, 2, 4, TrendDirection::Stable);
        $metrics = TrendMetrics::of(5, $mood, $sleep);

        $advice = new CbtAdvice(
            'A recurring pattern.',
            'Some cognitive distortions.',
            'A balanced perspective.',
            'A next action.'
        );

        $summary = new ProgressSummary('A narrative about progress.', $advice, $metrics);
        $outcome = SummaryOutcome::summary($summary);

        $range = DateRange::of(LocalDate::of(2025, 3, 1), LocalDate::of(2025, 3, 31));

        return SummaryController::render($range, $outcome);
    }
}
