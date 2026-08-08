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
use Diary\Diary\FoodDiary;
use Diary\Diary\QuestionDefinition;
use Diary\Diary\QuestionSet;
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
            . '    <header class="app-header">' . "\n"
            . '        <div class="app-header__bar">' . "\n"
            . '            <a class="app-header__back" href="/">Home</a>' . "\n"
            . '            <h1 class="app-header__title">' . $safeHeading . '</h1>' . "\n"
            . '        </div>' . "\n"
            . '    </header>' . "\n"
            . '    <main id="main">' . "\n"
            . self::renderMonthNav($calendarMonth->month())
            . self::renderGrid($calendarMonth, $selectedDate)
            . $detail
            . '    </main>' . "\n"
            . '</body>' . "\n"
            . '</html>' . "\n";
    }

    private static function renderMonthNav(YearMonth $month): string
    {
        $safeMonth = htmlspecialchars(
            $month->firstDay()->toDateTimeImmutable()->format('F Y'),
            ENT_QUOTES,
            'UTF-8'
        );
        $previousLink = self::monthLink($month->previous());
        $nextLink = self::monthLink($month->next());

        return '        <nav class="calendar-nav">' . "\n"
            . '            <a href="' . $previousLink . '">Previous month</a>' . "\n"
            . '            <span class="calendar-nav__month">' . $safeMonth . '</span>' . "\n"
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
        $cells = '';

        for ($day = 1; $day <= $month->lengthInDays(); $day++) {
            $date = LocalDate::of($month->year(), $month->month(), $day);
            $cells .= self::renderDateCell($calendarMonth, $date, $selectedDate);
        }

        return '        <div class="calendar-grid">' . "\n"
            . $cells
            . '        </div>' . "\n";
    }

    private static function renderDateCell(CalendarMonth $calendarMonth, LocalDate $date, ?LocalDate $selectedDate): string
    {
        $iso = $date->toIso();
        $safeIso = htmlspecialchars($iso, ENT_QUOTES, 'UTF-8');
        $safeDay = htmlspecialchars((string) $date->day(), ENT_QUOTES, 'UTF-8');

        $indicators = [];
        if ($calendarMonth->hasEntryOn($date)) {
            $indicators[] = '<span class="dot dot--entry entry-indicator" aria-label="Diary entry">'
                . '<span class="visually-hidden">Entry</span></span>';
        }
        if ($calendarMonth->hasMilestoneOn($date)) {
            $indicators[] = '<span class="dot dot--milestone milestone-indicator" aria-label="Milestone">'
                . '<span class="visually-hidden">Milestone</span></span>';
        }
        $indicatorHtml = $indicators === [] ? '' : implode('', $indicators);

        $selectPath = htmlspecialchars(
            AccessControlService::CALENDAR_PATH . '?' . self::MONTH_PARAM . '=' . $calendarMonth->month()->toIso()
                . '&' . self::DATE_PARAM . '=' . $iso,
            ENT_QUOTES,
            'UTF-8'
        );

        $isSelected = $selectedDate !== null && $selectedDate->equals($date);
        $cellClass = $isSelected ? 'calendar-cell calendar-cell--selected' : 'calendar-cell';
        $cellAttributes = $isSelected ? ' aria-current="date"' : '';

        return '            <a class="' . $cellClass . '" href="' . $selectPath . '"' . $cellAttributes
            . ' aria-label="Select ' . $safeIso . '">' . "\n"
            . '                <span class="calendar-cell__day">' . $safeDay . '</span>' . "\n"
            . '                <span class="calendar-cell__dots">' . $indicatorHtml . '</span>' . "\n"
            . '            </a>' . "\n";
    }

    /**
     * @param array{0: DiaryEntry, 1: ?CbtRecommendationRecord}|null $entryDetail
     */
    private static function renderDateDetail(?array $entryDetail, LocalDate $selectedDate): string
    {
        $heading = '            <p class="day-detail__eyebrow">' . ($entryDetail === null ? 'Selected day' : 'Diary entry') . '</p>' . "\n"
            . '            <h2 id="date-detail-heading" class="day-detail__date">' . self::longDate($selectedDate) . '</h2>' . "\n";

        if ($entryDetail === null) {
            $safeMessage = htmlspecialchars(self::NO_ENTRY_MESSAGE, ENT_QUOTES, 'UTF-8');

            return '        <section class="card day-detail day-detail--empty" aria-labelledby="date-detail-heading">' . "\n"
                . $heading
                . '            <p class="day-detail__empty-message">' . $safeMessage . '</p>' . "\n"
                . '        </section>' . "\n";
        }

        [$entry, $recommendation] = $entryDetail;

        return '        <section class="card card--spotlight day-detail" aria-labelledby="date-detail-heading">' . "\n"
            . $heading
            . self::renderEntry($entry)
            . self::renderRecommendation($recommendation)
            . '        </section>' . "\n";
    }

    /** e.g. "Sunday 1 June 2025" - how the day reads, not how it is stored. */
    private static function longDate(LocalDate $date): string
    {
        return htmlspecialchars($date->toDateTimeImmutable()->format('l j F Y'), ENT_QUOTES, 'UTF-8');
    }

    /**
     * The day's answers, driven off {@see QuestionSet} rather than a fixed
     * list, so this page cannot drift from the questions the entry form asks:
     * the ratings become score cards, and each written answer its own card.
     */
    private static function renderEntry(DiaryEntry $entry): string
    {
        $input = $entry->input();
        $scores = '';
        $answers = '';

        foreach (QuestionSet::definitions() as $question) {
            $value = $input->answer($question->field());

            if ($question->isScale()) {
                $scores .= self::renderScoreCard($question, is_int($value) ? $value : null);

                continue;
            }

            $answers .= self::renderAnswerCard($question, is_string($value) ? $value : '');
        }

        return '            <div class="day-scores">' . "\n" . $scores . '            </div>' . "\n"
            . '            <div class="day-answers">' . "\n" . $answers . '            </div>' . "\n"
            . self::renderFoodDiary($entry->input()->foodDiary());
    }

    private static function renderFoodDiary(FoodDiary $foodDiary): string
    {
        if ($foodDiary->isEmpty()) {
            return '';
        }

        $items = '';
        foreach ($foodDiary->meals() as $meal) {
            $safeType = htmlspecialchars($meal->typeLabel(), ENT_QUOTES, 'UTF-8');
            $safeDescription = htmlspecialchars($meal->description(), ENT_QUOTES, 'UTF-8');
            $notes = $meal->notes() === ''
                ? ''
                : '                    <p class="day-food__notes">' . htmlspecialchars($meal->notes(), ENT_QUOTES, 'UTF-8') . '</p>' . "\n";

            $items .= '                <li class="day-food__item">' . "\n"
                . '                    <span class="day-food__type">' . $safeType . '</span>' . "\n"
                . '                    <p class="day-food__description">' . $safeDescription . '</p>' . "\n"
                . $notes
                . '                </li>' . "\n";
        }

        return '            <section class="day-food" aria-label="Food diary">' . "\n"
            . '                <h3 class="day-food__heading">Food diary</h3>' . "\n"
            . '                <ul class="day-food__list">' . "\n"
            . $items
            . '                </ul>' . "\n"
            . '            </section>' . "\n";
    }

    /**
     * One rating, shown as its number against the scale it was given on, with
     * a meter for the shape of it at a glance. The meter's fill is a class
     * rather than an inline style: the Content-Security-Policy allows no
     * inline styles.
     */
    private static function renderScoreCard(QuestionDefinition $question, ?int $value): string
    {
        $safeLabel = htmlspecialchars($question->label(), ENT_QUOTES, 'UTF-8');
        $max = (int) $question->scaleMax();

        if ($value === null) {
            return '                <div class="day-score day-score--unanswered">' . "\n"
                . '                    <p class="day-score__label">' . $safeLabel . '</p>' . "\n"
                . '                    <p class="day-score__value">&ndash;</p>' . "\n"
                . '                    <p class="day-score__word">Not answered</p>' . "\n"
                . '                </div>' . "\n";
        }

        $percent = (int) round($value / $max * 10) * 10;
        $scaleLabels = $question->scaleLabels();
        // Only the points that carry a word of their own show one, so a 1-10
        // mood does not caption a 7 with "7".
        $word = isset($scaleLabels[$value])
            ? '                    <p class="day-score__word">' . htmlspecialchars($scaleLabels[$value], ENT_QUOTES, 'UTF-8') . '</p>' . "\n"
            : '';

        return '                <div class="day-score">' . "\n"
            . '                    <p class="day-score__label">' . $safeLabel . '</p>' . "\n"
            . '                    <p class="day-score__value">' . $value
            . '<span class="day-score__max"> / ' . $max . '</span></p>' . "\n"
            . '                    <div class="day-score__meter">' . "\n"
            . '                        <span class="day-score__fill day-score__fill--' . $percent . '"></span>' . "\n"
            . '                    </div>' . "\n"
            . $word
            . '                </div>' . "\n";
    }

    /**
     * One written answer, headed by the question that was asked rather than
     * the field's short name, so the day reads back the way it was written.
     */
    private static function renderAnswerCard(QuestionDefinition $question, string $value): string
    {
        $safeLabel = htmlspecialchars($question->label(), ENT_QUOTES, 'UTF-8');
        $safePrompt = htmlspecialchars($question->prompt(), ENT_QUOTES, 'UTF-8');

        $body = $value === ''
            ? '                    <p class="day-answer__text day-answer__text--blank">Nothing written for this one.</p>' . "\n"
            : '                    <p class="day-answer__text">' . htmlspecialchars($value, ENT_QUOTES, 'UTF-8') . '</p>' . "\n";

        return '                <article class="day-answer">' . "\n"
            . '                    <h3 class="day-answer__label">' . $safeLabel . '</h3>' . "\n"
            . '                    <p class="day-answer__prompt">' . $safePrompt . '</p>' . "\n"
            . $body
            . '                </article>' . "\n";
    }

    private static function renderRecommendation(?CbtRecommendationRecord $recommendation): string
    {
        if ($recommendation === null || !$recommendation->isGenerated()) {
            return '';
        }

        return FeedbackView::renderNotes($recommendation->recommendation())
            . FeedbackView::renderDisclaimer();
    }
}
