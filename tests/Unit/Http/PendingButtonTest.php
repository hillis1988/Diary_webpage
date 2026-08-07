<?php

declare(strict_types=1);

namespace Diary\Tests\Unit\Http;

use Diary\Ai\FeedbackOutcome;
use Diary\Diary\QuestionSet;
use Diary\Diary\SubmittedAnswers;
use Diary\Http\CsrfGuard;
use Diary\Http\DiaryEntryController;
use Diary\Http\FeedbackView;
use Diary\Http\PendingButton;
use Diary\Http\PositivesController;
use Diary\Http\SummaryController;
use Diary\Support\DateRange;
use Diary\Support\LocalDate;
use PHPUnit\Framework\TestCase;

/**
 * The waiting state on the AI-backed submit buttons.
 *
 * The point of these assertions is the pairing: a button carrying
 * {@see PendingButton::PENDING_LABEL_ATTRIBUTE} is inert unless the page it
 * sits on also links `/assets/app.js`, and app.js only enhances buttons that
 * carry the attribute. Either half added without the other is a button that
 * silently goes back to looking unclicked for the several seconds the provider
 * takes, so every AI surface is checked for both.
 */
final class PendingButtonTest extends TestCase
{
    private const SCRIPT_TAG = '<script src="/assets/app.js" defer></script>';

    public function testRendersASubmitButtonCarryingTheWaitingLabel(): void
    {
        $html = PendingButton::render('View summary', 'Building your summary…');

        self::assertSame(
            '<button type="submit" class="button" data-pending-label="Building your summary…">View summary</button>',
            $html,
        );
    }

    public function testTheWaitingLabelIsEscapedAndTheRestingLabelIsLeftAsGivenHtml(): void
    {
        $html = PendingButton::render('Save today&rsquo;s entry', 'Saving "it" & waiting', 'button button--save');

        self::assertStringContainsString('class="button button--save"', $html);
        self::assertStringContainsString('>Save today&rsquo;s entry</button>', $html);
        self::assertStringContainsString('data-pending-label="Saving &quot;it&quot; &amp; waiting"', $html);
    }

    public function testTheSummaryRangePickerWaitsAndThePageLinksTheScript(): void
    {
        $html = SummaryController::render(self::range(), null);

        self::assertStringContainsString(PendingButton::PENDING_LABEL_ATTRIBUTE, $html);
        self::assertStringContainsString(self::SCRIPT_TAG, $html);
    }

    public function testTheBrightSpotsRangePickerWaitsAndThePageLinksTheScript(): void
    {
        $html = PositivesController::render(self::range(), null);

        self::assertStringContainsString(PendingButton::PENDING_LABEL_ATTRIBUTE, $html);
        self::assertStringContainsString(self::SCRIPT_TAG, $html);
    }

    public function testTheDiaryEntrySaveButtonWaitsAndThePageLinksTheScript(): void
    {
        $html = DiaryEntryController::render(
            QuestionSet::definitions(),
            SubmittedAnswers::blank(),
            null,
            [],
            'csrf-token',
            null,
        );

        self::assertStringContainsString(PendingButton::PENDING_LABEL_ATTRIBUTE, $html);
        self::assertStringContainsString(self::SCRIPT_TAG, $html);
    }

    public function testTheFeedbackRetryControlWaits(): void
    {
        $html = FeedbackView::render(
            FeedbackOutcome::unavailable(),
            DiaryEntryController::RETRY_FEEDBACK_PATH,
            [DiaryEntryController::RETRY_DATE_FIELD => '2025-03-10'],
            CsrfGuard::FIELD_NAME,
            'csrf-token',
        );

        self::assertStringContainsString(PendingButton::PENDING_LABEL_ATTRIBUTE, $html);
        self::assertStringContainsString(FeedbackView::RETRY_BUTTON_LABEL, $html);
    }

    private static function range(): DateRange
    {
        return DateRange::of(LocalDate::fromString('2025-03-01'), LocalDate::fromString('2025-03-31'));
    }
}
