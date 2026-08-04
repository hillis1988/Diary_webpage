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
     * The Trend_Line_Chart's fixed SVG coordinate space (`viewBox="0 0 360
     * 160"`): room on the left for scale labels, margins so point markers
     * are not clipped, and space under the plot for date labels.
     */
    private const CHART_VIEW_WIDTH = 360.0;
    private const CHART_VIEW_HEIGHT = 160.0;
    private const CHART_LEFT_X = 36.0;
    private const CHART_RIGHT_X = 348.0;
    private const CHART_CENTER_X = 192.0;
    private const CHART_TOP_Y = 12.0;
    private const CHART_BOTTOM_Y = 118.0;
    private const CHART_PLOT_HEIGHT = self::CHART_BOTTOM_Y - self::CHART_TOP_Y;
    private const CHART_LABEL_Y = 138.0;

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
            default => self::renderUnavailable($outcome),
        };

        return '        <div class="ai-summary card card--spotlight">' . "\n"
            . $body
            . FeedbackView::renderDisclaimer()
            . '        </div>' . "\n";
    }

    private static function renderUnavailable(SummaryOutcome $outcome): string
    {
        $html = self::renderMessage($outcome->reason() ?? SummaryOutcome::UNAVAILABLE_MESSAGE);
        $metrics = $outcome->metrics();

        if ($metrics !== null) {
            $html .= self::renderMetrics($metrics);
        }

        return $html;
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
        return '            <p class="summary-metrics__count">Entries considered: <strong>'
            . $metrics->entryCount() . '</strong></p>' . "\n"
            . self::renderSeries('Mood rating', $metrics->mood(), QuestionSet::MOOD_MIN, QuestionSet::MOOD_MAX)
            . self::renderSeries('Sleep quality', $metrics->sleep(), QuestionSet::SLEEP_MIN, QuestionSet::SLEEP_MAX);
    }

    /**
     * A spotlight panel per series: titled chart, direction badge, and a
     * compact stats grid derived from what {@see SeriesStats} already exposes.
     */
    private static function renderSeries(string $label, SeriesStats $stats, int $scaleMin, int $scaleMax): string
    {
        $safeLabel = htmlspecialchars($label, ENT_QUOTES, 'UTF-8');
        $chart = self::renderLineChart($stats, $scaleMin, $scaleMax);
        $directionBadge = '';

        if ($chart !== '') {
            $safeDirection = htmlspecialchars(self::directionLabel($stats->direction()), ENT_QUOTES, 'UTF-8');
            $directionValue = htmlspecialchars($stats->direction()->value, ENT_QUOTES, 'UTF-8');
            $directionBadge = '                    <span class="badge badge--direction-' . $directionValue . '">' . $safeDirection . '</span>' . "\n";
        }

        return '            <section class="trend-panel">' . "\n"
            . '                <div class="trend-panel__header">' . "\n"
            . '                    <h2 class="trend-panel__title">' . $safeLabel . '</h2>' . "\n"
            . $directionBadge
            . '                </div>' . "\n"
            . $chart
            . '                <div class="trend-panel__stats">' . "\n"
            . self::renderTrendStat('Count', (string) $stats->count())
            . self::renderTrendStat('Mean', self::formatNullableScaledNumber($stats->mean(), $scaleMax))
            . self::renderTrendStat('Minimum', self::formatNullableScaledNumber($stats->min(), $scaleMax))
            . self::renderTrendStat('Maximum', self::formatNullableScaledNumber($stats->max(), $scaleMax))
            . '                </div>' . "\n"
            . '            </section>' . "\n";
    }

    private static function renderTrendStat(string $label, string $value): string
    {
        $safeLabel = htmlspecialchars($label, ENT_QUOTES, 'UTF-8');
        $safeValue = htmlspecialchars($value, ENT_QUOTES, 'UTF-8');

        return '                    <div class="trend-stat">' . "\n"
            . '                        <span class="trend-stat__label">' . $safeLabel . '</span>' . "\n"
            . '                        <span class="trend-stat__value">' . $safeValue . '</span>' . "\n"
            . '                    </div>' . "\n";
    }

    /**
     * A `.trend-chart` inline SVG: grid, scale labels, area fill under the
     * line, mean guide, polyline + point markers, and end-date labels.
     * Rendered only when there is at least one point to plot.
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

        $safeDirectionLabel = htmlspecialchars(self::directionLabel($stats->direction()), ENT_QUOTES, 'UTF-8');
        $meanY = self::formatCoordinate(self::valueToY($mean, $scaleMin, $scaleMax));
        $gradientId = 'trendAreaFill-' . $scaleMin . '-' . $scaleMax;

        return '                <div class="trend">' . "\n"
            . '                    <div class="trend__label">' . "\n"
            . '                        <span>Low ' . self::formatNullableScaledNumber($min, $scaleMax) . '</span>' . "\n"
            . '                        <span>High ' . self::formatNullableScaledNumber($max, $scaleMax) . '</span>' . "\n"
            . '                    </div>' . "\n"
            . '                    <div class="trend-chart">' . "\n"
            . '                        <svg class="trend-chart__svg" viewBox="0 0 '
            . self::formatCoordinate(self::CHART_VIEW_WIDTH) . ' '
            . self::formatCoordinate(self::CHART_VIEW_HEIGHT)
            . '" preserveAspectRatio="xMidYMid meet" role="img" aria-label="' . $safeDirectionLabel . '">' . "\n"
            . '                            <defs>' . "\n"
            . '                                <linearGradient id="' . $gradientId . '" x1="0" y1="0" x2="0" y2="1">' . "\n"
            . '                                    <stop offset="0%" stop-color="#e0a526" stop-opacity="0.45"></stop>' . "\n"
            . '                                    <stop offset="100%" stop-color="#e0a526" stop-opacity="0.02"></stop>' . "\n"
            . '                                </linearGradient>' . "\n"
            . '                            </defs>' . "\n"
            . self::renderChartGrid($scaleMin, $scaleMax)
            . '                            <line class="trend-chart__mean-line" x1="' . self::formatCoordinate(self::CHART_LEFT_X) . '" y1="' . $meanY . '" x2="' . self::formatCoordinate(self::CHART_RIGHT_X) . '" y2="' . $meanY . '"></line>' . "\n"
            . self::renderArea($points, $scaleMin, $scaleMax, $gradientId)
            . self::renderPolyline($points, $scaleMin, $scaleMax)
            . self::renderPointMarkers($points, $scaleMin, $scaleMax)
            . self::renderDateLabels($points, $scaleMin, $scaleMax)
            . '                        </svg>' . "\n"
            . '                    </div>' . "\n"
            . '                </div>' . "\n";
    }

    private static function renderChartGrid(int $scaleMin, int $scaleMax): string
    {
        $html = '';
        $ticks = 4;

        for ($i = 0; $i <= $ticks; $i++) {
            $ratio = $i / $ticks;
            $value = $scaleMin + ($scaleMax - $scaleMin) * (1 - $ratio);
            $y = self::CHART_TOP_Y + self::CHART_PLOT_HEIGHT * $ratio;
            $yFormatted = self::formatCoordinate($y);
            $label = htmlspecialchars(self::formatNullableScaledNumber($value, $scaleMax), ENT_QUOTES, 'UTF-8');

            $html .= '                            <line class="trend-chart__grid" x1="'
                . self::formatCoordinate(self::CHART_LEFT_X) . '" y1="' . $yFormatted . '" x2="'
                . self::formatCoordinate(self::CHART_RIGHT_X) . '" y2="' . $yFormatted . '"></line>' . "\n";
            $html .= '                            <text class="trend-chart__axis-label" x="'
                . self::formatCoordinate(self::CHART_LEFT_X - 4) . '" y="'
                . self::formatCoordinate($y + 3) . '" text-anchor="end">' . $label . '</text>' . "\n";
        }

        return $html;
    }

    /**
     * @param list<TrendPoint> $points
     */
    private static function renderArea(array $points, int $scaleMin, int $scaleMax, string $gradientId): string
    {
        $coordinates = self::plottedCoordinates($points, $scaleMin, $scaleMax);

        if ($coordinates === []) {
            return '';
        }

        $path = [];
        foreach ($coordinates as [$x, $y]) {
            $path[] = self::formatCoordinate($x) . ',' . self::formatCoordinate($y);
        }

        $firstX = self::formatCoordinate($coordinates[0][0]);
        $lastX = self::formatCoordinate($coordinates[array_key_last($coordinates)][0]);
        $baseY = self::formatCoordinate(self::CHART_BOTTOM_Y);
        $safeGradientId = htmlspecialchars($gradientId, ENT_QUOTES, 'UTF-8');

        return '                            <polygon class="trend-chart__area" fill="url(#' . $safeGradientId . ')" points="'
            . $firstX . ',' . $baseY . ' ' . implode(' ', $path) . ' ' . $lastX . ',' . $baseY
            . '"></polygon>' . "\n";
    }

    /**
     * @param list<TrendPoint> $points
     */
    private static function renderDateLabels(array $points, int $scaleMin, int $scaleMax): string
    {
        if ($points === []) {
            return '';
        }

        $coordinates = self::plottedCoordinates($points, $scaleMin, $scaleMax);
        $indices = [0];

        if (count($points) > 2) {
            $indices[] = (int) floor((count($points) - 1) / 2);
        }

        if (count($points) > 1) {
            $indices[] = count($points) - 1;
        }

        $indices = array_values(array_unique($indices));
        $html = '';

        foreach ($indices as $index) {
            $label = htmlspecialchars($points[$index]->date()->toIso(), ENT_QUOTES, 'UTF-8');
            $x = self::formatCoordinate($coordinates[$index][0]);
            $anchor = $index === 0 ? 'start' : ($index === count($points) - 1 ? 'end' : 'middle');

            $html .= '                            <text class="trend-chart__axis-label" x="'
                . $x . '" y="' . self::formatCoordinate(self::CHART_LABEL_Y)
                . '" text-anchor="' . $anchor . '">' . $label . '</text>' . "\n";
        }

        return $html;
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
            $markers .= '                        <circle class="trend-chart__point" cx="' . self::formatCoordinate($x) . '" cy="' . self::formatCoordinate($y) . '" r="4"></circle>' . "\n";
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
