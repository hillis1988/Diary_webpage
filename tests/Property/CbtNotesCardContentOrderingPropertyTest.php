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
 * Property 8: The CBT_Notes_Card renders the narrative and all four advice
 * parts, each under its label, in order.
 *
 * For any {@see ProgressSummary} whose narrative and four {@see CbtAdvice}
 * fields are arbitrary non-empty text deliberately containing `<`, `>`,
 * `&`, and `"`, {@see SummaryController::render()}'s rendered HTML always
 * contains: the "Summary" label followed by the HTML-escaped narrative,
 * followed by the "Advice" label, followed by the "Pattern" label and its
 * HTML-escaped content, followed by the "Cognitive distortions" label and
 * its HTML-escaped content, followed by the "Balanced perspective" label
 * and its HTML-escaped content, followed by the "Next step" label and its
 * HTML-escaped content - a strictly increasing sequence of positions - and
 * never the raw, unescaped source text for any of the five fields.
 *
 * Requirements: 6.1, 6.2.
 */
final class CbtNotesCardContentOrderingPropertyTest extends TestCase
{
    use TestTrait;

    // Feature: summary-json-payload, Property 8: The CBT_Notes_Card renders the narrative and all four advice parts, each under its label, in order
    public function testCbtNotesCardRendersNarrativeAndAdvicePartsInOrderWithEscaping(): void
    {
        $this->limitTo(100)
            ->forAll(
                self::dangerousText('narrative'),
                self::dangerousText('pattern'),
                self::dangerousText('distortions'),
                self::dangerousText('balanced-perspective'),
                self::dangerousText('next-action')
            )
            ->then(function (
                string $narrative,
                string $pattern,
                string $distortions,
                string $balancedPerspective,
                string $nextAction
            ): void {
                $advice = new CbtAdvice($pattern, $distortions, $balancedPerspective, $nextAction);
                $metrics = TrendMetrics::of(5, self::fixedSeriesStats(QuestionSet::MOOD_MIN, QuestionSet::MOOD_MAX), self::fixedSeriesStats(QuestionSet::SLEEP_MIN, QuestionSet::SLEEP_MAX));
                $summary = new ProgressSummary($narrative, $advice, $metrics);
                $outcome = SummaryOutcome::summary($summary);

                $range = DateRange::of(LocalDate::of(2024, 1, 1), LocalDate::of(2024, 1, 31));

                $html = SummaryController::render($range, $outcome);

                $safeNarrative = htmlspecialchars($narrative, ENT_QUOTES, 'UTF-8');
                $safePattern = htmlspecialchars($pattern, ENT_QUOTES, 'UTF-8');
                $safeDistortions = htmlspecialchars($distortions, ENT_QUOTES, 'UTF-8');
                $safeBalancedPerspective = htmlspecialchars($balancedPerspective, ENT_QUOTES, 'UTF-8');
                $safeNextAction = htmlspecialchars($nextAction, ENT_QUOTES, 'UTF-8');

                // Escaped content is present; the raw, unescaped source text never leaks in literally.
                self::assertStringContainsString($safeNarrative, $html, 'expected the HTML-escaped narrative in the rendered HTML');
                self::assertStringNotContainsString($narrative, $html, 'the raw, unescaped narrative must not appear literally in the rendered HTML');

                self::assertStringContainsString($safePattern, $html, 'expected the HTML-escaped pattern in the rendered HTML');
                self::assertStringNotContainsString($pattern, $html, 'the raw, unescaped pattern must not appear literally in the rendered HTML');

                self::assertStringContainsString($safeDistortions, $html, 'expected the HTML-escaped distortions in the rendered HTML');
                self::assertStringNotContainsString($distortions, $html, 'the raw, unescaped distortions must not appear literally in the rendered HTML');

                self::assertStringContainsString($safeBalancedPerspective, $html, 'expected the HTML-escaped balanced perspective in the rendered HTML');
                self::assertStringNotContainsString($balancedPerspective, $html, 'the raw, unescaped balanced perspective must not appear literally in the rendered HTML');

                self::assertStringContainsString($safeNextAction, $html, 'expected the HTML-escaped next action in the rendered HTML');
                self::assertStringNotContainsString($nextAction, $html, 'the raw, unescaped next action must not appear literally in the rendered HTML');

                // Ordering: Summary label, narrative, Advice label, then each advice label/content pair in order.
                $positions = [
                    'Summary label' => strpos($html, '<h4 class="cbt-notes__label">Summary</h4>'),
                    'narrative content' => strpos($html, $safeNarrative),
                    'Advice label' => strpos($html, '<h4 class="cbt-notes__label">Advice</h4>'),
                    'Pattern label' => strpos($html, '<h5 class="cbt-notes__advice-label">Pattern</h5>'),
                    'pattern content' => strpos($html, $safePattern),
                    'Cognitive distortions label' => strpos($html, '<h5 class="cbt-notes__advice-label">Cognitive distortions</h5>'),
                    'distortions content' => strpos($html, $safeDistortions),
                    'Balanced perspective label' => strpos($html, '<h5 class="cbt-notes__advice-label">Balanced perspective</h5>'),
                    'balanced perspective content' => strpos($html, $safeBalancedPerspective),
                    'Next step label' => strpos($html, '<h5 class="cbt-notes__advice-label">Next step</h5>'),
                    'next action content' => strpos($html, $safeNextAction),
                ];

                $previousLabel = null;
                $previousPosition = null;
                foreach ($positions as $label => $position) {
                    self::assertNotFalse($position, sprintf('expected to find "%s" in the rendered HTML', $label));

                    if ($previousPosition !== null) {
                        self::assertGreaterThan(
                            $previousPosition,
                            $position,
                            sprintf('expected "%s" (%d) to appear after "%s" (%d)', $label, $position, $previousLabel, $previousPosition)
                        );
                    }

                    $previousLabel = $label;
                    $previousPosition = $position;
                }
            });
    }

    /**
     * Non-empty text guaranteed to contain `<`, `>`, `&`, and `"` mixed into
     * otherwise plain text, distinguished per-field by a fixed $tag prefix
     * so the five generated fields never collide with each other or with
     * fixed label markup.
     */
    private static function dangerousText(string $tag): \Eris\Generator
    {
        return Generator\map(
            static function (array $parts) use ($tag): string {
                [$prefix, $snippet, $suffix] = $parts;

                return trim(sprintf('%s %s %s %s', $tag, $prefix, $snippet, $suffix));
            },
            Generator\tuple(self::plainWords(), self::dangerousSnippet(), self::plainWords())
        );
    }

    private static function plainWords(): \Eris\Generator
    {
        return Generator\map(
            static fn (array $words): string => implode(' ', $words),
            Generator\vector(3, Generator\elements([
                'alpha', 'beta', 'gamma', 'delta', 'epsilon', 'notes', 'today', 'feeling', 'progress', 'session',
            ]))
        );
    }

    /** A snippet that always contains all four of `<`, `>`, `&`, `"`. */
    private static function dangerousSnippet(): \Eris\Generator
    {
        return Generator\elements([
            '<div class="x">A & B</div>',
            '<script>alert("hi")</script> & more',
            'text with < and > and & and "quotes"',
        ]);
    }

    private static function fixedSeriesStats(int $scaleMin, int $scaleMax): SeriesStats
    {
        return SeriesStats::of(5, (float) $scaleMin, $scaleMin, $scaleMax, TrendDirection::Stable);
    }
}
