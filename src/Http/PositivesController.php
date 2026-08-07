<?php

declare(strict_types=1);

namespace Diary\Http;

use Diary\Access\AccessControlService;
use Diary\Access\Decision;
use Diary\Ai\AiPositivesService;
use Diary\Ai\PositiveHighlight;
use Diary\Ai\PositivesOutcome;
use Diary\Ai\PositivesReminder;
use Diary\Auth\SecurityContext;
use Diary\Support\Clock;
use Diary\Support\DateRange;
use Diary\Support\LocalDate;
use Diary\Support\Operation;

/**
 * Bright Spots page: optimistic, friend-toned reminders drawn from past diary
 * data. Authorised as ReadDiaryData so owners and viewers can open it.
 */
final class PositivesController
{
    public const HEADING = 'Bright spots';

    public const START_PARAM = 'start';
    public const END_PARAM = 'end';

    private const DEFAULT_RANGE_DAYS = 90;

    public function __construct(
        private readonly AccessControlService $access,
        private readonly AiPositivesService $positivesService,
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
        $outcome = $this->positivesService->remind($owner, $submittedRange);

        return Response::html(self::render($submittedRange, $outcome));
    }

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
        return Operation::readDiaryData('positives.view', $request->pathWithQuery());
    }

    public static function render(DateRange $formRange, ?PositivesOutcome $outcome): string
    {
        $safeHeading = htmlspecialchars(self::HEADING, ENT_QUOTES, 'UTF-8');

        return '<!DOCTYPE html>' . "\n"
            . '<html lang="en">' . "\n"
            . '<head>' . "\n"
            . '    <meta charset="utf-8">' . "\n"
            . '    <meta name="viewport" content="width=device-width, initial-scale=1">' . "\n"
            . '    <title>' . $safeHeading . '</title>' . "\n"
            . '    <link rel="stylesheet" href="/assets/app.css">' . "\n"
            . '    <script src="/assets/app.js" defer></script>' . "\n"
            . '</head>' . "\n"
            . '<body class="page-bright-spots">' . "\n"
            . '    <header class="app-header">' . "\n"
            . '        <div class="app-header__bar">' . "\n"
            . '            <a class="app-header__back" href="/">Home</a>' . "\n"
            . '            <h1 class="app-header__title">' . $safeHeading . '</h1>' . "\n"
            . '        </div>' . "\n"
            . '    </header>' . "\n"
            . '    <main id="main">' . "\n"
            . self::renderHero()
            . '        <div class="card">' . "\n"
            . self::renderRangePicker($formRange)
            . '        </div>' . "\n"
            . ($outcome !== null ? self::renderOutcome($outcome) : '')
            . '    </main>' . "\n"
            . '</body>' . "\n"
            . '</html>' . "\n";
    }

    private static function renderHero(): string
    {
        return '        <section class="bright-spots-hero">' . "\n"
            . '            <p class="bright-spots-hero__eyebrow">A note from a friend</p>' . "\n"
            . '            <h2 class="bright-spots-hero__title">Look how far you have already come</h2>' . "\n"
            . '            <p class="bright-spots-hero__lead">Pick a stretch of your diary and I will '
            . 'pull out the wins, the brave little steps, and the days worth remembering — then gently '
            . 'nudge you to keep going.</p>' . "\n"
            . '        </section>' . "\n";
    }

    private static function renderRangePicker(DateRange $formRange): string
    {
        $safeStart = htmlspecialchars($formRange->start()->toIso(), ENT_QUOTES, 'UTF-8');
        $safeEnd = htmlspecialchars($formRange->end()->toIso(), ENT_QUOTES, 'UTF-8');
        $safeAction = htmlspecialchars(AccessControlService::BRIGHT_SPOTS_PATH, ENT_QUOTES, 'UTF-8');

        return '            <form method="get" action="' . $safeAction . '">' . "\n"
            . '                <div class="field-group">' . "\n"
            . '                    <label for="' . self::START_PARAM . '">From</label>' . "\n"
            . '                    <input type="date" id="' . self::START_PARAM . '" name="' . self::START_PARAM . '" value="' . $safeStart . '" required>' . "\n"
            . '                </div>' . "\n"
            . '                <div class="field-group">' . "\n"
            . '                    <label for="' . self::END_PARAM . '">To</label>' . "\n"
            . '                    <input type="date" id="' . self::END_PARAM . '" name="' . self::END_PARAM . '" value="' . $safeEnd . '" required>' . "\n"
            . '                </div>' . "\n"
            . '                ' . PendingButton::render('Show my bright spots', 'Finding your bright spots…') . "\n"
            . '            </form>' . "\n";
    }

    private static function renderOutcome(PositivesOutcome $outcome): string
    {
        $body = match (true) {
            $outcome->isReminder() => self::renderReminder($outcome->reminderValue()),
            $outcome->isInsufficientData() => self::renderMessage($outcome->reason() ?? PositivesOutcome::INSUFFICIENT_DATA_MESSAGE),
            default => self::renderMessage($outcome->reason() ?? PositivesOutcome::UNAVAILABLE_MESSAGE),
        };

        return '        <div class="bright-spots-result card card--spotlight">' . "\n"
            . $body
            . FeedbackView::renderDisclaimer()
            . '        </div>' . "\n";
    }

    private static function renderReminder(PositivesReminder $reminder): string
    {
        $safeGreeting = htmlspecialchars($reminder->greeting(), ENT_QUOTES, 'UTF-8');
        $safeEncouragement = htmlspecialchars($reminder->encouragement(), ENT_QUOTES, 'UTF-8');

        $items = '';
        foreach ($reminder->highlights() as $highlight) {
            $items .= self::renderHighlight($highlight);
        }

        return '            <p class="bright-spots-greeting">' . $safeGreeting . '</p>' . "\n"
            . '            <p class="bright-spots-encouragement">' . $safeEncouragement . '</p>' . "\n"
            . '            <ul class="bright-spot-list">' . "\n"
            . $items
            . '            </ul>' . "\n";
    }

    private static function renderHighlight(PositiveHighlight $highlight): string
    {
        $safeDate = htmlspecialchars(self::formatDisplayDate($highlight->date()), ENT_QUOTES, 'UTF-8');
        $safeTitle = htmlspecialchars($highlight->title(), ENT_QUOTES, 'UTF-8');
        $safeWhy = htmlspecialchars($highlight->whyItMattered(), ENT_QUOTES, 'UTF-8');
        $safeNudge = htmlspecialchars($highlight->keepGoing(), ENT_QUOTES, 'UTF-8');

        return '                <li class="bright-spot">' . "\n"
            . '                    <span class="bright-spot__date">' . $safeDate . '</span>' . "\n"
            . '                    <h3 class="bright-spot__title">' . $safeTitle . '</h3>' . "\n"
            . '                    <p class="bright-spot__why"><span class="bright-spot__label">Why it mattered</span>'
            . $safeWhy . '</p>' . "\n"
            . '                    <p class="bright-spot__nudge"><span class="bright-spot__label">Keep going</span>'
            . $safeNudge . '</p>' . "\n"
            . '                </li>' . "\n";
    }

    private static function formatDisplayDate(string $isoDate): string
    {
        $date = LocalDate::tryFromString($isoDate);

        if ($date === null) {
            return $isoDate;
        }

        return $date->toDateTimeImmutable()->format('j F Y');
    }

    private static function renderMessage(string $message): string
    {
        $safeMessage = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');

        return '            <p>' . $safeMessage . '</p>' . "\n";
    }
}
