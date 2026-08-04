<?php

declare(strict_types=1);

namespace Diary\Http;

use Diary\Access\AccessControlService;
use Diary\Access\Decision;
use Diary\Ai\AiSummaryService;
use Diary\Ai\ProgressSummary;
use Diary\Ai\SeriesStats;
use Diary\Ai\SharedTrendAxis;
use Diary\Ai\SummaryOutcome;
use Diary\Ai\TrendDirection;
use Diary\Ai\TrendMetrics;
use Diary\Ai\TrendPoint;
use Diary\Auth\SecurityContext;
use Diary\Diary\QuestionSet;
use Diary\Support\Clock;
use Diary\Support\DateRange;
use Diary\Support\LocalDate;
use Diary\Support\Operation;

/**
 * The summary page (Requirements 9.1, 9.2, 9.4, 9.5, 9.6).
 *
 * A single GET on {@see AccessControlService::SUMMARY_PATH}, authorised as
 * {@see \Diary\Support\OperationKind::ReadDiaryData} - the same read kind the
 * calendar and milestone list use - so a viewer context can open it just as
 * Requirement 7.2 requires; an anonymous request is sent to the login page
 * and nothing here ever writes anything.
 *
 * The date range comes from `?start=YYYY-MM-DD&end=YYYY-MM-DD`, a plain GET
 * form so the resulting URL is shareable and bookmarkable. Requirement 9.6's
 * disclaimer renders whenever {@see AiSummaryService::summarise()} was
 * actually invoked, regardless of outcome - and design.md is explicit that
 * this is a *literal* "when invoked" trigger, not "whenever this page is
 * open": until both `start` and `end` are present and parse as real dates,
 * the service is never called, so the page shows only the range picker and
 * no disclaimer. The two inputs default to the last 30 days purely to give
 * the form sensible starting values; that default is never submitted on the
 * caller's behalf.
 */
final class SummaryController
{
    public const HEADING = 'Progress summary';

    public const START_PARAM = 'start';
    public const END_PARAM = 'end';

    /** The picker's pre-filled span when no range has been submitted yet. */
    private const DEFAULT_RANGE_DAYS = 30;

    /**
     * The Trend_Line_Chart's fixed SVG coordinate space (`viewBox="0 0 300
     * 60"`): a left/right plotting margin so points near the ends of the
     * date range are not clipped by their circle markers, and a top/bottom
     * margin for the same reason on the value axis.
     */
    private const CHART_LEFT_X = 10.0;
    private const CHART_RIGHT_X = 290.0;
    private const CHART_CENTER_X = 150.0;
    private const CHART_TOP_Y = 5.0;
    private const CHART_BOTTOM_Y = 55.0;
    private const CHART_PLOT_HEIGHT = self::CHART_BOTTOM_Y - self::CHART_TOP_Y;

    public function __construct(
        private readonly AccessControlService $access,
        private readonly AiSummaryService $summaryService,
        private readonly Clock $clock,
    ) {
    }

    public function show(Request $request): Response
    {
        $context = SessionResolverMiddleware::contextOf($request);

        $denied = $this->authorise($context, self::viewOperation($request));
        if ($denied !== null) {
            return $denied;
        }

        $submittedRange = self::resolveSubmittedRange($request);

        if ($submittedRange === null) {
            return Response::html(self::render(self::defaultRange($this->clock), null));
        }

        $owner = $this->access->resolveDataOwner($context);
        $outcome = $this->summaryService->summarise($owner, $submittedRange);

        return Response::html(self::render($submittedRange, $outcome));
    }

    /**
     * The submitted range, only when both `start` and `end` are present and
     * each parses as a real date; null otherwise, meaning the service must
     * not be invoked at all (Requirement 9.6).
     */
    private static function resolveSubmittedRange(Request $request): ?DateRange
    {
        $rawStart = $request->queryParam(self::START_PARAM);
        $rawEnd = $request->queryParam(self::END_PARAM);

        if ($rawStart === null || $rawEnd === null) {
            return null;
        }

        $start = LocalDate::tryFromString($rawStart);
        $end = LocalDate::tryFromString($rawEnd);

        if ($start === null || $end === null) {
            return null;
        }

        return DateRange::of($start, $end);
    }

    private static function defaultRange(Clock $clock): DateRange
    {
        $today = LocalDate::today($clock);

        return DateRange::of($today->minusDays(self::DEFAULT_RANGE_DAYS - 1), $today);
    }

    /**
     * A denial for an unauthenticated or viewer-write attempt, rendered the
     * same way {@see AuthorisationMiddleware} would; null when the operation
     * is allowed and the caller should proceed.
     */
    private function authorise(SecurityContext $context, Operation $operation): ?Response
    {
        $decision = $this->access->authorise($context, $operation);

        if ($decision->isAllowed()) {
            return null;
        }

        if ($decision->isRedirectToLogin()) {
            return Response::redirect((string) $decision->location(), Decision::REDIRECT_STATUS);
        }

        return StatusPage::response(
            Decision::DENIED_STATUS,
            AuthorisationMiddleware::DENIED_HEADING,
            (string) $decision->message()
        );
    }

    private static function viewOperation(Request $request): Operation
    {
        return Operation::readDiaryData('summary.view', $request->pathWithQuery());
    }

    public static function render(DateRange $formRange, ?SummaryOutcome $outcome): string
    {
        $safeHeading = htmlspecialchars(self::HEADING, ENT_QUOTES, 'UTF-8');

        return '<!DOCTYPE html>' . "\n"
            . '<html lang="en">' . "\n"
            . '<head>' . "\n"
            . '    <meta charset="utf-8">' . "\n"
            . '    <meta name="viewport" content="width=device-width, initial-scale=1">' . "\n"
            . '    <title>' . $safeHeading . '</title>' . "\n"
            . '    <link rel="stylesheet" href="/assets/app.css">' . "\n"
            . '</head>' . "\n"
            . '<body>' . "\n"
            . '    <header class="app-header">' . "\n"
            . '        <div class="app-header__bar">' . "\n"
            . '            <a class="app-header__back" href="/">Home</a>' . "\n"
            . '            <h1 class="app-header__title">' . $safeHeading . '</h1>' . "\n"
            . '        </div>' . "\n"
            . '    </header>' . "\n"
            . '    <main id="main">' . "\n"
            . '        <div class="card">' . "\n"
            . self::renderRangePicker($formRange)
            . '        </div>' . "\n"
            . ($outcome !== null ? self::renderOutcome($outcome) : '')
            . '    </main>' . "\n"
            . '</body>' . "\n"
            . '</html>' . "\n";
    }

    private static function renderRangePicker(DateRange $formRange): string
    {
        $safeStart = htmlspecialchars($formRange->start()->toIso(), ENT_QUOTES, 'UTF-8');
        $safeEnd = htmlspecialchars($formRange->end()->toIso(), ENT_QUOTES, 'UTF-8');
        $safeAction = htmlspecialchars(AccessControlService::SUMMARY_PATH, ENT_QUOTES, 'UTF-8');

        return '            <form method="get" action="' . $safeAction . '">' . "\n"
            . '                <div class="field-group">' . "\n"
            . '                    <label for="' . self::START_PARAM . '">Start date</label>' . "\n"
            . '                    <input type="date" id="' . self::START_PARAM . '" name="' . self::START_PARAM . '" value="' . $safeStart . '" required>' . "\n"
            . '                </div>' . "\n"
            . '                <div class="field-group">' . "\n"
            . '                    <label for="' . self::END_PARAM . '">End date</label>' . "\n"
            . '                    <input type="date" id="' . self::END_PARAM . '" name="' . self::END_PARAM . '" value="' . $safeEnd . '" required>' . "\n"
            . '                </div>' . "\n"
            . '                <button type="submit" class="button">View summary</button>' . "\n"
            . '            </form>' . "\n";
    }

    /**
     * The disclaimer plus the outcome-specific body, rendered together
     * because this method is only ever called once the service has actually
     * been invoked (Requirement 9.6).
     */
    private static function renderOutcome(SummaryOutcome $outcome): string
    {
        $body = match (true) {
            $outcome->isSummary() => self::renderSummary($outcome),
            $outcome->isInsufficientData() => self::renderMessage($outcome->reason() ?? SummaryOutcome::INSUFFICIENT_DATA_MESSAGE),
            default => self::renderMessage($outcome->reason() ?? SummaryOutcome::UNAVAILABLE_MESSAGE),
        };

        return '        <div class="ai-summary card">' . "\n"
            . $body
            . FeedbackView::renderDisclaimer()
            . '        </div>' . "\n";
    }

    private static function renderSummary(SummaryOutcome $outcome): string
    {
        $summary = $outcome->summaryValue();

        try {
            $notesCard = self::renderCbtNotesCard($summary);
        } catch (\Throwable) {
            $notesCard = self::renderNotesCardFallback();
        }

        return $notesCard . self::renderMetrics($summary->metrics());
    }

    /**
     * The narrative and CBT advice presented in the same "AI CBT
     * Therapist's notes" card {@see FeedbackView::renderNotes()} uses for a
     * recommendation (Requirements 6.1, 6.2, 6.3, 6.4), reusing its heading
     * text and CSS classes so the two AI surfaces look identical apart from
     * their content.
     */
    private static function renderCbtNotesCard(ProgressSummary $summary): string
    {
        $safeHeading = htmlspecialchars(FeedbackView::NOTES_HEADING, ENT_QUOTES, 'UTF-8');
        $safeNarrative = htmlspecialchars($summary->narrative(), ENT_QUOTES, 'UTF-8');

        $advice = $summary->advice();
        $safePattern = htmlspecialchars($advice->pattern(), ENT_QUOTES, 'UTF-8');
        $safeDistortions = htmlspecialchars($advice->distortions(), ENT_QUOTES, 'UTF-8');
        $safeBalancedPerspective = htmlspecialchars($advice->balancedPerspective(), ENT_QUOTES, 'UTF-8');
        $safeNextAction = htmlspecialchars($advice->nextAction(), ENT_QUOTES, 'UTF-8');

        return '            <div class="cbt-notes">' . "\n"
            . '                <hr class="cbt-notes__divider">' . "\n"
            . '                <h3 class="cbt-notes__heading">' . $safeHeading . '</h3>' . "\n"
            . '                <div class="cbt-notes__section">' . "\n"
            . '                    <h4 class="cbt-notes__label">Summary</h4>' . "\n"
            . '                    <p>' . $safeNarrative . '</p>' . "\n"
            . '                </div>' . "\n"
            . '                <div class="cbt-notes__section">' . "\n"
            . '                    <h4 class="cbt-notes__label">Advice</h4>' . "\n"
            . '                    <div class="cbt-notes__advice-part">' . "\n"
            . '                        <h5 class="cbt-notes__advice-label">Pattern</h5>' . "\n"
            . '                        <p>' . $safePattern . '</p>' . "\n"
            . '                    </div>' . "\n"
            . '                    <div class="cbt-notes__advice-part">' . "\n"
            . '                        <h5 class="cbt-notes__advice-label">Cognitive distortions</h5>' . "\n"
            . '                        <p>' . $safeDistortions . '</p>' . "\n"
            . '                    </div>' . "\n"
            . '                    <div class="cbt-notes__advice-part">' . "\n"
            . '                        <h5 class="cbt-notes__advice-label">Balanced perspective</h5>' . "\n"
            . '                        <p>' . $safeBalancedPerspective . '</p>' . "\n"
            . '                    </div>' . "\n"
            . '                    <div class="cbt-notes__advice-part">' . "\n"
            . '                        <h5 class="cbt-notes__advice-label">Next step</h5>' . "\n"
            . '                        <p>' . $safeNextAction . '</p>' . "\n"
            . '                    </div>' . "\n"
            . '                </div>' . "\n"
            . '            </div>' . "\n";
    }

    /**
     * The same outer `cbt-notes` card, shown when {@see renderCbtNotesCard()}
     * throws - a defensive fallback so a rendering failure never breaks the
     * whole summary page.
     */
    private static function renderNotesCardFallback(): string
    {
        $safeHeading = htmlspecialchars(FeedbackView::NOTES_HEADING, ENT_QUOTES, 'UTF-8');
        $safeMessage = htmlspecialchars(
            "The AI CBT Therapist's notes could not be displayed.",
            ENT_QUOTES,
            'UTF-8'
        );

        return '            <div class="cbt-notes">' . "\n"
            . '                <hr class="cbt-notes__divider">' . "\n"
            . '                <h3 class="cbt-notes__heading">' . $safeHeading . '</h3>' . "\n"
            . '                <p>' . $safeMessage . '</p>' . "\n"
            . '            </div>' . "\n";
    }

    private static function renderMetrics(TrendMetrics $metrics): string
    {
        return '            <dl>' . "\n"
            . '                <dt>Entries considered</dt><dd>' . $metrics->entryCount() . '</dd>' . "\n"
            . '            </dl>' . "\n"
            . self::renderSeries('Mood rating', $metrics->mood(), QuestionSet::MOOD_MIN, QuestionSet::MOOD_MAX)
            . self::renderSeries('Sleep quality', $metrics->sleep(), QuestionSet::SLEEP_MIN, QuestionSet::SLEEP_MAX);
    }

    /**
     * The series' existing numeric breakdown (unchanged), plus a min-max
     * range bar with the mean marked and a direction badge - both derived
     * purely from what {@see SeriesStats} already exposes, positioned as
     * percentages of the question's fixed scale ($scaleMin-$scaleMax).
     */
    private static function renderSeries(string $label, SeriesStats $stats, int $scaleMin, int $scaleMax): string
    {
        $safeLabel = htmlspecialchars($label, ENT_QUOTES, 'UTF-8');
        $safeDirection = htmlspecialchars(self::directionLabel($stats->direction()), ENT_QUOTES, 'UTF-8');

        return '            <h2>' . $safeLabel . '</h2>' . "\n"
            . self::renderLineChart($stats, $scaleMin, $scaleMax)
            . '            <dl>' . "\n"
            . '                <dt>Count</dt><dd>' . $stats->count() . '</dd>' . "\n"
            . '                <dt>Mean</dt><dd>' . self::formatNullableScaledNumber($stats->mean(), $scaleMax) . '</dd>' . "\n"
            . '                <dt>Minimum</dt><dd>' . self::formatNullableScaledNumber($stats->min(), $scaleMax) . '</dd>' . "\n"
            . '                <dt>Maximum</dt><dd>' . self::formatNullableScaledNumber($stats->max(), $scaleMax) . '</dd>' . "\n"
            . '                <dt>Direction</dt><dd>' . $safeDirection . '</dd>' . "\n"
            . '            </dl>' . "\n";
    }

    /**
     * A `.trend` card containing a `.trend-chart` inline SVG line chart: one
     * `.trend-chart__point` circle plus connecting `.trend-chart__line`
     * polyline per {@see TrendPoint} (x = the point's date position within
     * the plotted range, y = {@see SharedTrendAxis::positionOf()} inverted
     * for SVG's downward-growing y axis), a dashed `.trend-chart__mean-line`
     * at the mean's shared-axis position, and a `.badge--direction-*`
     * naming the direction - rendered only when there is at least one point
     * in the series, since an empty series has nothing to plot.
     */
    private static function renderLineChart(SeriesStats $stats, int $scaleMin, int $scaleMax): string
    {
        $min = $stats->min();
        $max = $stats->max();
        $mean = $stats->mean();
        $points = $stats->points();

        if ($stats->count() === 0 || $min === null || $max === null || $mean === null) {
            return '';
        }

        $directionValue = htmlspecialchars($stats->direction()->value, ENT_QUOTES, 'UTF-8');
        $safeDirectionLabel = htmlspecialchars(self::directionLabel($stats->direction()), ENT_QUOTES, 'UTF-8');

        $meanY = self::formatCoordinate(self::valueToY($mean, $scaleMin, $scaleMax));

        return '            <div class="trend">' . "\n"
            . '                <div class="trend__label">' . "\n"
            . '                    <span>' . self::formatNullableScaledNumber($min, $scaleMax) . '</span>' . "\n"
            . '                    <span>' . self::formatNullableScaledNumber($max, $scaleMax) . '</span>' . "\n"
            . '                </div>' . "\n"
            . '                <div class="trend-chart">' . "\n"
            . '                    <svg class="trend-chart__svg" viewBox="0 0 300 60" preserveAspectRatio="none" role="img" aria-label="' . $safeDirectionLabel . '">' . "\n"
            . '                        <line class="trend-chart__mean-line" x1="' . self::formatCoordinate(self::CHART_LEFT_X) . '" y1="' . $meanY . '" x2="' . self::formatCoordinate(self::CHART_RIGHT_X) . '" y2="' . $meanY . '"></line>' . "\n"
            . self::renderPolyline($points, $scaleMin, $scaleMax)
            . self::renderPointMarkers($points, $scaleMin, $scaleMax)
            . '                    </svg>' . "\n"
            . '                </div>' . "\n"
            . '                <div class="trend__direction">' . "\n"
            . '                    <span class="badge badge--direction-' . $directionValue . '">' . $safeDirectionLabel . '</span>' . "\n"
            . '                </div>' . "\n"
            . '            </div>' . "\n";
    }

    /**
     * A single `.trend-chart__line` polyline through every point, in
     * chronological order. Rendered even for a single point (a
     * zero-length/degenerate polyline), so the point marker is the only
     * visible mark - matching the "place it centred" rule for a single
     * point or a series where every point shares one date.
     *
     * @param list<TrendPoint> $points
     */
    private static function renderPolyline(array $points, int $scaleMin, int $scaleMax): string
    {
        if ($points === []) {
            return '';
        }

        $coordinates = [];
        foreach (self::plottedCoordinates($points, $scaleMin, $scaleMax) as [$x, $y]) {
            $coordinates[] = self::formatCoordinate($x) . ',' . self::formatCoordinate($y);
        }

        return '                        <polyline class="trend-chart__line" points="' . implode(' ', $coordinates) . '"></polyline>' . "\n";
    }

    /**
     * A `.trend-chart__point` circle marker at each actual data point, so
     * the line is legible rather than a bare polyline.
     *
     * @param list<TrendPoint> $points
     */
    private static function renderPointMarkers(array $points, int $scaleMin, int $scaleMax): string
    {
        $markers = '';

        foreach (self::plottedCoordinates($points, $scaleMin, $scaleMax) as [$x, $y]) {
            $markers .= '                        <circle class="trend-chart__point" cx="' . self::formatCoordinate($x) . '" cy="' . self::formatCoordinate($y) . '" r="2.5"></circle>' . "\n";
        }

        return $markers;
    }

    /**
     * Each point's plotted `[x, y]` SVG coordinate: x proportional to the
     * point's date position within the earliest-to-latest plotted range
     * (centred when every point shares one date, or there is only one
     * point), y via {@see valueToY()}.
     *
     * Returns an empty list when given no points - this can happen for a
     * SeriesStats built without a points list (every call site predating
     * {@see TrendPoint}), even though count() is non-zero; there is simply
     * nothing to plot a line through in that case.
     *
     * @param list<TrendPoint> $points
     * @return list<array{0: float, 1: float}>
     */
    private static function plottedCoordinates(array $points, int $scaleMin, int $scaleMax): array
    {
        if ($points === []) {
            return [];
        }

        $epochDays = array_map(
            static fn (TrendPoint $point): int => $point->date()->toEpochDay(),
            $points
        );
        $earliest = min($epochDays);
        $latest = max($epochDays);
        $dateSpan = $latest - $earliest;

        $coordinates = [];

        foreach ($points as $index => $point) {
            $x = $dateSpan > 0
                ? self::CHART_LEFT_X + ($epochDays[$index] - $earliest) / $dateSpan * (self::CHART_RIGHT_X - self::CHART_LEFT_X)
                : self::CHART_CENTER_X;

            $coordinates[] = [$x, self::valueToY($point->value(), $scaleMin, $scaleMax)];
        }

        return $coordinates;
    }

    /**
     * A value's y-coordinate in the chart's SVG space, via {@see
     * SharedTrendAxis::positionOf()} - the same shared-axis scaling the
     * numeric labels are deliberately kept independent from - inverted
     * because SVG's y axis grows downward while a higher value should plot
     * higher (visually, nearer the top) on screen.
     */
    private static function valueToY(int|float $value, int $scaleMin, int $scaleMax): float
    {
        $axisPosition = SharedTrendAxis::positionOf($value, $scaleMin, $scaleMax);

        return self::CHART_BOTTOM_Y - ($axisPosition / 10) * self::CHART_PLOT_HEIGHT;
    }

    private static function formatCoordinate(float $coordinate): string
    {
        return number_format($coordinate, 2);
    }

    private static function directionLabel(TrendDirection $direction): string
    {
        return ucfirst($direction->value);
    }

    private static function formatNullableNumber(int|float|null $value): string
    {
        if ($value === null) {
            return 'n/a';
        }

        return is_float($value) ? number_format($value, 1) : (string) $value;
    }

    /**
     * The value formatted on its own native scale (never the shared axis),
     * with a "/$scaleMax" suffix (e.g. "/10" for mood, "/5" for sleep) so
     * readers know which scale a number belongs to. No suffix for a null
     * value - it stays plain "n/a".
     */
    private static function formatNullableScaledNumber(int|float|null $value, int $scaleMax): string
    {
        $formatted = self::formatNullableNumber($value);

        if ($value === null) {
            return $formatted;
        }

        return $formatted . '/' . $scaleMax;
    }

    private static function renderMessage(string $message): string
    {
        $safeMessage = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');

        return '            <p>' . $safeMessage . '</p>' . "\n";
    }
}
