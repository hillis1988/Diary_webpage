<?php

declare(strict_types=1);

namespace Diary\Http;

use Diary\Access\AccessControlService;
use Diary\Access\Decision;
use Diary\Access\OwnerId;
use Diary\Ai\CbtRecommendationRecord;
use Diary\Ai\CbtRecommendationRepository;
use Diary\Auth\SecurityContext;
use Diary\Diary\CalendarMonth;
use Diary\Diary\CalendarService;
use Diary\Diary\DiaryEntry;
use Diary\Diary\DiaryService;
use Diary\Support\Clock;
use Diary\Support\LocalDate;
use Diary\Support\Operation;
use Diary\Support\YearMonth;

/**
 * The Calendar_View (Requirements 8.1, 8.2, 8.3, 8.4, 8.5).
 *
 * A single GET on {@see AccessControlService::CALENDAR_PATH}, authorised as
 * {@see \Diary\Support\OperationKind::ReadDiaryData} - the same read kind the
 * milestone list and summary pages use - so a viewer context can open it just
 * as Requirement 7.2 and 8.5 require; an anonymous request is sent to the
 * login page and nothing here ever writes anything.
 *
 * The month grid comes from `?month=YYYY-MM` (defaulting to the current
 * month when absent or unparsable) and is built by
 * {@see CalendarService::calendarMonth()}, which scopes both the entry and
 * milestone dates to the session's resolved data owner - never the signed-in
 * user's own id in a viewer context (Requirement 8.5).
 *
 * An optional `?date=YYYY-MM-DD` selects one date's detail underneath the
 * grid (Requirement 8.2, 8.4): an owner-scoped entry lookup, then either the
 * entry plus its {@see CbtRecommendationRecord} when one exists, or the
 * fixed no-entry message when it does not. A selected date is not required to
 * fall inside the displayed month - the request that follows a "select a
 * date" link always carries a `date` some month contains, but nothing here
 * needs to assume that to answer Requirement 8.2/8.4 correctly for it.
 */
final class CalendarController
{
    public const HEADING = 'Calendar';

    /** Requirement 8.4's fixed wording. */
    public const NO_ENTRY_MESSAGE = 'No entry exists for that date.';

    public const MONTH_PARAM = 'month';
    public const DATE_PARAM = 'date';

    public function __construct(
        private readonly AccessControlService $access,
        private readonly CalendarService $calendarService,
        private readonly DiaryService $diaryService,
        private readonly CbtRecommendationRepository $cbtRecommendations,
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

        $owner = $this->access->resolveDataOwner($context);
        $month = self::resolveMonth($request, $this->clock);

        $calendarMonth = $this->calendarService->calendarMonth($owner, $month);

        $selectedDate = self::resolveSelectedDate($request);
        $detail = $selectedDate !== null
            ? self::renderDateDetail($this->entryDetailFor($owner, $selectedDate), $selectedDate)
            : '';

        return Response::html(self::render($calendarMonth, $selectedDate, $detail));
    }

    /**
     * @return array{0: DiaryEntry, 1: ?CbtRecommendationRecord}|null null when
     *     no entry exists for this date for this owner
     */
    private function entryDetailFor(OwnerId $owner, LocalDate $date): ?array
    {
        $entry = $this->diaryService->entryForDate($owner, $date);

        if ($entry === null) {
            return null;
        }

        return [$entry, $this->cbtRecommendations->findByEntryId($entry->id())];
    }

    private static function resolveMonth(Request $request, Clock $clock): YearMonth
    {
        $raw = $request->queryParam(self::MONTH_PARAM);

        if ($raw === null) {
            return YearMonth::current($clock);
        }

        return YearMonth::tryFromString($raw) ?? YearMonth::current($clock);
    }

    private static function resolveSelectedDate(Request $request): ?LocalDate
    {
        $raw = $request->queryParam(self::DATE_PARAM);

        if ($raw === null) {
            return null;
        }

        return LocalDate::tryFromString($raw);
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
        return Operation::readDiaryData('calendar.view', $request->pathWithQuery());
    }

    private static function render(CalendarMonth $calendarMonth, ?LocalDate $selectedDate, string $detail): string
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
            . '    <main id="main">' . "\n"
            . '        <p><a href="/">Home</a></p>' . "\n"
            . '        <h1>' . $safeHeading . '</h1>' . "\n"
            . self::renderMonthNav($calendarMonth->month())
            . self::renderGrid($calendarMonth, $selectedDate)
            . $detail
            . '    </main>' . "\n"
            . '</body>' . "\n"
            . '</html>' . "\n";
    }

    private static function renderMonthNav(YearMonth $month): string
    {
        $safeMonth = htmlspecialchars($month->toIso(), ENT_QUOTES, 'UTF-8');
        $previousLink = self::monthLink($month->previous());
        $nextLink = self::monthLink($month->next());

        return '        <nav>' . "\n"
            . '            <a href="' . $previousLink . '">Previous month</a>' . "\n"
            . '            <span>' . $safeMonth . '</span>' . "\n"
            . '            <a href="' . $nextLink . '">Next month</a>' . "\n"
            . '        </nav>' . "\n";
    }

    private static function monthLink(YearMonth $month): string
    {
        return htmlspecialchars(
            AccessControlService::CALENDAR_PATH . '?' . self::MONTH_PARAM . '=' . $month->toIso(),
            ENT_QUOTES,
            'UTF-8'
        );
    }

    private static function renderGrid(CalendarMonth $calendarMonth, ?LocalDate $selectedDate): string
    {
        $month = $calendarMonth->month();
        $rows = '';

        for ($day = 1; $day <= $month->lengthInDays(); $day++) {
            $date = LocalDate::of($month->year(), $month->month(), $day);
            $rows .= self::renderDateRow($calendarMonth, $date, $selectedDate);
        }

        return '        <table>' . "\n"
            . '            <thead>' . "\n"
            . '                <tr><th>Date</th><th>Indicators</th><th></th></tr>' . "\n"
            . '            </thead>' . "\n"
            . '            <tbody>' . "\n"
            . $rows
            . '            </tbody>' . "\n"
            . '        </table>' . "\n";
    }

    private static function renderDateRow(CalendarMonth $calendarMonth, LocalDate $date, ?LocalDate $selectedDate): string
    {
        $iso = $date->toIso();
        $safeIso = htmlspecialchars($iso, ENT_QUOTES, 'UTF-8');

        $indicators = [];
        if ($calendarMonth->hasEntryOn($date)) {
            $indicators[] = '<span class="entry-indicator" aria-label="Diary entry">Entry</span>';
        }
        if ($calendarMonth->hasMilestoneOn($date)) {
            $indicators[] = '<span class="milestone-indicator" aria-label="Milestone">Milestone</span>';
        }
        $indicatorHtml = $indicators === [] ? '' : implode(' ', $indicators);

        $selectPath = htmlspecialchars(
            AccessControlService::CALENDAR_PATH . '?' . self::MONTH_PARAM . '=' . $calendarMonth->month()->toIso()
                . '&' . self::DATE_PARAM . '=' . $iso,
            ENT_QUOTES,
            'UTF-8'
        );

        $isSelected = $selectedDate !== null && $selectedDate->equals($date);
        $rowAttributes = $isSelected ? ' aria-current="date"' : '';

        return '                <tr' . $rowAttributes . '>' . "\n"
            . '                    <td>' . $safeIso . '</td>' . "\n"
            . '                    <td>' . $indicatorHtml . '</td>' . "\n"
            . '                    <td><a href="' . $selectPath . '">Select</a></td>' . "\n"
            . '                </tr>' . "\n";
    }

    /**
     * @param array{0: DiaryEntry, 1: ?CbtRecommendationRecord}|null $entryDetail
     */
    private static function renderDateDetail(?array $entryDetail, LocalDate $selectedDate): string
    {
        $safeDate = htmlspecialchars($selectedDate->toIso(), ENT_QUOTES, 'UTF-8');

        if ($entryDetail === null) {
            $safeMessage = htmlspecialchars(self::NO_ENTRY_MESSAGE, ENT_QUOTES, 'UTF-8');

            return '        <section aria-labelledby="date-detail-heading">' . "\n"
                . '            <h2 id="date-detail-heading">' . $safeDate . '</h2>' . "\n"
                . '            <p>' . $safeMessage . '</p>' . "\n"
                . '        </section>' . "\n";
        }

        [$entry, $recommendation] = $entryDetail;

        return '        <section aria-labelledby="date-detail-heading">' . "\n"
            . '            <h2 id="date-detail-heading">' . $safeDate . '</h2>' . "\n"
            . self::renderEntry($entry)
            . self::renderRecommendation($recommendation)
            . '        </section>' . "\n";
    }

    private static function renderEntry(DiaryEntry $entry): string
    {
        $input = $entry->input();
        $safeEvents = htmlspecialchars($input->events(), ENT_QUOTES, 'UTF-8');
        $safeThoughts = htmlspecialchars($input->thoughts(), ENT_QUOTES, 'UTF-8');
        $safeEmotions = htmlspecialchars($input->emotions(), ENT_QUOTES, 'UTF-8');
        $sleepQuality = $input->sleepQuality();

        return '            <dl>' . "\n"
            . '                <dt>Mood rating</dt><dd>' . $input->moodRating() . '</dd>' . "\n"
            . '                <dt>Sleep quality</dt><dd>' . ($sleepQuality !== null ? $sleepQuality : '') . '</dd>' . "\n"
            . '                <dt>Notable events</dt><dd>' . $safeEvents . '</dd>' . "\n"
            . '                <dt>Thoughts</dt><dd>' . $safeThoughts . '</dd>' . "\n"
            . '                <dt>Emotions</dt><dd>' . $safeEmotions . '</dd>' . "\n"
            . '            </dl>' . "\n";
    }

    private static function renderRecommendation(?CbtRecommendationRecord $recommendation): string
    {
        if ($recommendation === null || !$recommendation->isGenerated()) {
            return '';
        }

        $cbt = $recommendation->recommendation();
        $safeFocus = htmlspecialchars($cbt->positiveFocus(), ENT_QUOTES, 'UTF-8');
        $safeChange = htmlspecialchars($cbt->suggestedChange(), ENT_QUOTES, 'UTF-8');

        return '            <div class="ai-feedback">' . "\n"
            . '                <p>' . $safeFocus . '</p>' . "\n"
            . '                <p>' . $safeChange . '</p>' . "\n"
            . '            </div>' . "\n";
    }
}
