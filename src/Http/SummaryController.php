<?php

declare(strict_types=1);

namespace Diary\Http;

use Diary\Access\AccessControlService;
use Diary\Access\Decision;
use Diary\Ai\AiSummaryService;
use Diary\Ai\SeriesStats;
use Diary\Ai\SummaryOutcome;
use Diary\Ai\TrendDirection;
use Diary\Ai\TrendMetrics;
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
        $safeNarrative = htmlspecialchars($summary->narrative(), ENT_QUOTES, 'UTF-8');

        return '            <p>' . $safeNarrative . '</p>' . "\n"
            . self::renderMetrics($summary->metrics());
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
            . self::renderTrendBar($stats, $scaleMin, $scaleMax)
            . '            <dl>' . "\n"
            . '                <dt>Count</dt><dd>' . $stats->count() . '</dd>' . "\n"
            . '                <dt>Mean</dt><dd>' . self::formatNullableNumber($stats->mean()) . '</dd>' . "\n"
            . '                <dt>Minimum</dt><dd>' . self::formatNullableNumber($stats->min()) . '</dd>' . "\n"
            . '                <dt>Maximum</dt><dd>' . self::formatNullableNumber($stats->max()) . '</dd>' . "\n"
            . '                <dt>Direction</dt><dd>' . $safeDirection . '</dd>' . "\n"
            . '            </dl>' . "\n";
    }

    /**
     * A `.trend` gauge: the track spans the question's fixed scale, `.trend__fill`
     * covers the observed min-max range, `.trend__mean` marks the mean within it,
     * and a `.badge--direction-*` names the direction - rendered only when there
     * is at least one value in the series, since an empty series has no
     * min/max/mean to place on the track.
     */
    private static function renderTrendBar(SeriesStats $stats, int $scaleMin, int $scaleMax): string
    {
        $min = $stats->min();
        $max = $stats->max();
        $mean = $stats->mean();

        if ($min === null || $max === null || $mean === null) {
            return '';
        }

        $scaleSpan = $scaleMax - $scaleMin;
        $minPercent = self::percentOfScale($min, $scaleMin, $scaleSpan);
        $maxPercent = self::percentOfScale($max, $scaleMin, $scaleSpan);
        $widthPercent = max(0.0, $maxPercent - $minPercent);
        $meanPercent = self::percentOfScale($mean, $scaleMin, $scaleSpan);

        $directionValue = htmlspecialchars($stats->direction()->value, ENT_QUOTES, 'UTF-8');
        $safeDirectionLabel = htmlspecialchars(self::directionLabel($stats->direction()), ENT_QUOTES, 'UTF-8');

        return '            <div class="trend">' . "\n"
            . '                <div class="trend__label">' . "\n"
            . '                    <span>' . self::formatNullableNumber($min) . '</span>' . "\n"
            . '                    <span>' . self::formatNullableNumber($max) . '</span>' . "\n"
            . '                </div>' . "\n"
            . '                <div class="trend__track">' . "\n"
            . '                    <div class="trend__fill" style="left: ' . self::formatPercent($minPercent) . '%; width: ' . self::formatPercent($widthPercent) . '%;"></div>' . "\n"
            . '                    <div class="trend__mean" style="left: ' . self::formatPercent($meanPercent) . '%;"></div>' . "\n"
            . '                </div>' . "\n"
            . '                <div class="trend__direction">' . "\n"
            . '                    <span class="badge badge--direction-' . $directionValue . '">' . $safeDirectionLabel . '</span>' . "\n"
            . '                </div>' . "\n"
            . '            </div>' . "\n";
    }

    /** A value's position along [$scaleMin, $scaleMin + $scaleSpan] as 0-100, clamped. */
    private static function percentOfScale(int|float $value, int $scaleMin, int $scaleSpan): float
    {
        if ($scaleSpan <= 0) {
            return 0.0;
        }

        $percent = (($value - $scaleMin) / $scaleSpan) * 100;

        return max(0.0, min(100.0, $percent));
    }

    private static function formatPercent(float $percent): string
    {
        return number_format($percent, 1);
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

    private static function renderMessage(string $message): string
    {
        $safeMessage = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');

        return '            <p>' . $safeMessage . '</p>' . "\n";
    }
}
