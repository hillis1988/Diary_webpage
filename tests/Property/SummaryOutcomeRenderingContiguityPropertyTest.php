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
 * Property 9: A Progress_Summary outcome renders the notes card, metrics,
 * and disclaimer contiguously; any other outcome renders only its message.
 *
 * For any SummaryOutcome carrying a ProgressSummary, the rendered page's
 * CBT_Notes_Card is immediately followed by the trend metrics, immediately
 * followed by the disclaimer - nothing but whitespace between any of the
 * three - and no InsufficientData/Unavailable message text appears
 * anywhere in that render.
 *
 * For any InsufficientData or Unavailable outcome, the rendered page
 * contains that outcome's plain message and the disclaimer, but no
 * CBT_Notes_Card markup and no trend-bar/metrics markup.
 *
 * Requirements: 6.4, 6.5.
 */
final class SummaryOutcomeRenderingContiguityPropertyTest extends TestCase
{
    use TestTrait;

    // Feature: summary-json-payload, Property 9: A Progress_Summary outcome renders the notes card, metrics, and disclaimer contiguously; any other outcome renders only its message
    public function testProgressSummaryOutcomeRendersNotesCardMetricsAndDisclaimerContiguously(): void
    {
        $this->limitTo(100)
            ->forAll(
                self::narrativeAndAdviceText(),
                self::narrativeAndAdviceText(),
                self::narrativeAndAdviceText(),
                self::narrativeAndAdviceText(),
                self::narrativeAndAdviceText(),
                self::seriesShape(QuestionSet::MOOD_MIN, QuestionSet::MOOD_MAX),
                self::seriesShape(QuestionSet::SLEEP_MIN, QuestionSet::SLEEP_MAX)
            )
            ->then(function (
                string $narrative,
                string $pattern,
                string $distortions,
                string $balancedPerspective,
                string $nextAction,
                array $moodShape,
                array $sleepShape
            ): void {
                $mood = self::toSeriesStats($moodShape);
                $sleep = self::toSeriesStats($sleepShape);
                $metrics = TrendMetrics::of($mood->count() + $sleep->count(), $mood, $sleep);
                $advice = new CbtAdvice($pattern, $distortions, $balancedPerspective, $nextAction);
                $summary = new ProgressSummary($narrative, $advice, $metrics);
                $outcome = SummaryOutcome::summary($summary);

                $range = DateRange::of(LocalDate::of(2025, 3, 1), LocalDate::of(2025, 3, 31));
                $html = SummaryController::render($range, $outcome);

                $notesPos = strpos($html, 'class="cbt-notes"');
                $metricsPos = strpos($html, '<p class="summary-metrics__count"');
                $disclaimerPos = strpos($html, '<p class="disclaimer"');

                self::assertNotFalse($notesPos, 'The CBT_Notes_Card must render');
                self::assertNotFalse($metricsPos, 'The trend metrics must render');
                self::assertNotFalse($disclaimerPos, 'The disclaimer must render');

                self::assertTrue(
                    $notesPos < $metricsPos && $metricsPos < $disclaimerPos,
                    'The notes card, metrics, and disclaimer must appear in that order'
                );

                // Nothing but whitespace between the notes card's closing tag
                // and the metrics' opening entry count.
                $beforeMetrics = substr($html, 0, $metricsPos);
                $lastDivClosePos = strrpos($beforeMetrics, '</div>');
                self::assertNotFalse($lastDivClosePos, 'The notes card must close with a </div> before the metrics');
                $gapBeforeMetrics = substr(
                    $html,
                    $lastDivClosePos + strlen('</div>'),
                    $metricsPos - ($lastDivClosePos + strlen('</div>'))
                );
                self::assertSame(
                    '',
                    trim($gapBeforeMetrics),
                    'Nothing but whitespace may sit between the notes card and the metrics'
                );

                // Nothing but whitespace between the last trend panel's
                // closing </section> and the disclaimer's opening <p>.
                $beforeDisclaimer = substr($html, 0, $disclaimerPos);
                $lastPanelClosePos = strrpos($beforeDisclaimer, '</section>');
                self::assertNotFalse($lastPanelClosePos, 'The metrics must close with a </section> before the disclaimer');
                $gapBeforeDisclaimer = substr(
                    $html,
                    $lastPanelClosePos + strlen('</section>'),
                    $disclaimerPos - ($lastPanelClosePos + strlen('</section>'))
                );
                self::assertSame(
                    '',
                    trim($gapBeforeDisclaimer),
                    'Nothing but whitespace may sit between the metrics and the disclaimer'
                );

                self::assertStringNotContainsString(
                    SummaryOutcome::INSUFFICIENT_DATA_MESSAGE,
                    $html,
                    'A Progress_Summary render must not contain the insufficient-data message'
                );
                self::assertStringNotContainsString(
                    SummaryOutcome::UNAVAILABLE_MESSAGE,
                    $html,
                    'A Progress_Summary render must not contain the unavailable message'
                );
            });
    }

    // Feature: summary-json-payload, Property 9: A Progress_Summary outcome renders the notes card, metrics, and disclaimer contiguously; any other outcome renders only its message
    public function testInsufficientDataAndUnavailableOutcomesRenderOnlyTheirMessage(): void
    {
        $this->limitTo(100)
            ->forAll(
                Generator\elements(['insufficient_data', 'unavailable']),
                self::reasonText()
            )
            ->then(function (string $kind, string $reason): void {
                $outcome = $kind === 'insufficient_data'
                    ? SummaryOutcome::insufficientData($reason)
                    : SummaryOutcome::unavailable($reason);

                $range = DateRange::of(LocalDate::of(2025, 3, 1), LocalDate::of(2025, 3, 31));
                $html = SummaryController::render($range, $outcome);

                self::assertStringContainsString(
                    htmlspecialchars($reason, ENT_QUOTES, 'UTF-8'),
                    $html,
                    'The outcome\'s own message must render'
                );
                self::assertStringContainsString(
                    'class="disclaimer"',
                    $html,
                    'The disclaimer must still render'
                );
                self::assertStringNotContainsString(
                    'class="cbt-notes"',
                    $html,
                    'No CBT_Notes_Card markup may render for a non-summary outcome'
                );
                self::assertStringNotContainsString(
                    'class="summary-metrics__count"',
                    $html,
                    'No trend metrics markup may render for a non-summary outcome'
                );
                self::assertStringNotContainsString(
                    'class="trend"',
                    $html,
                    'No trend-bar markup may render for a non-summary outcome'
                );
                self::assertStringNotContainsString(
                    'class="trend-panel"',
                    $html,
                    'No trend panel markup may render for a non-summary outcome'
                );
            });
    }

    /**
     * A flat [count, min, max, direction] shape - kept a plain array rather
     * than a SeriesStats to avoid combining several nested tuple/map
     * generators together, which otherwise triggers unbounded shrink-tree
     * construction in Eris. Converted to SeriesStats via toSeriesStats().
     */
    private static function seriesShape(int $scaleMin, int $scaleMax): \Eris\Generator
    {
        return Generator\oneOf(
            Generator\constant([0, null, null]),
            Generator\map(
                static function (array $bounds): array {
                    [$boundA, $boundB] = $bounds;

                    return [5, min($boundA, $boundB), max($boundA, $boundB)];
                },
                Generator\tuple(
                    Generator\choose($scaleMin, $scaleMax),
                    Generator\choose($scaleMin, $scaleMax)
                )
            )
        );
    }

    private static function toSeriesStats(array $shape): SeriesStats
    {
        [$count, $min, $max] = $shape;

        if ($count === 0) {
            return SeriesStats::of(0, null, null, null, TrendDirection::Stable);
        }

        $mean = ((float) $min + (float) $max) / 2;

        return SeriesStats::of($count, $mean, $min, $max, TrendDirection::Stable);
    }

    /**
     * Arbitrary non-empty text for the narrative/advice fields, including
     * HTML-special characters that must survive escaping without breaking
     * the ordering/contiguity assertions above.
     */
    private static function narrativeAndAdviceText(): \Eris\Generator
    {
        return Generator\map(
            static fn (array $characters): string => 'Text ' . implode('', $characters),
            Generator\vector(
                8,
                Generator\elements(str_split('abcdefghijklmnop <>&", .'))
            )
        );
    }

    /** Arbitrary non-empty reason text for an InsufficientData/Unavailable outcome. */
    private static function reasonText(): \Eris\Generator
    {
        return Generator\map(
            static fn (array $characters): string => 'Reason ' . implode('', $characters),
            Generator\vector(
                8,
                Generator\elements(str_split('abcdefghijklmnop .,'))
            )
        );
    }
}
