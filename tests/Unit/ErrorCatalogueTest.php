<?php

declare(strict_types=1);

namespace Diary\Tests\Unit;

use Diary\Access\AccessControlService;
use Diary\Access\Decision;
use Diary\Ai\FeedbackOutcome;
use Diary\Ai\SummaryOutcome;
use Diary\Auth\AuthService;
use Diary\Auth\DefaultPasswordPolicy;
use Diary\Diary\DiaryInputValidator;
use Diary\Diary\QuestionSet;
use Diary\Http\HomePageController;
use Diary\Milestone\MilestoneInputValidator;
use PHPUnit\Framework\TestCase;

/**
 * Task 18.2: a single source of truth for the fixed content and error
 * catalogue wording the acceptance criteria promise verbatim.
 *
 * Most of these constants already have coverage where they are produced
 * (AuthServiceRegistrationTest, AuthServiceAuthenticateTest,
 * DefaultPasswordPolicyTest, AccessControlServiceTest,
 * HomePageControllerTest, PromptBuilderTest), asserted with assertSame
 * against the literal wording. This file exists for the messages that
 * either had no exact-match assertion anywhere (Requirement 2.5's
 * session-ended message, Requirement 10.4's milestone field messages) or
 * were only ever confirmed indirectly through a controller's rendered HTML
 * (Requirements 5.5, 6.5, 9.4, 9.5), and to gather the whole catalogue in
 * one place so a future accidental wording change - anywhere - shows up as
 * a single failing assertion here rather than only as a stale needle match
 * in an unrelated controller test.
 *
 * This file does not re-test behaviour (ordering, precedence, rejection
 * logic); it only pins wording. Behavioural coverage lives beside each
 * component's own tests.
 */
final class ErrorCatalogueTest extends TestCase
{
    /** Requirement 3.1. */
    public function testHomePageBanner(): void
    {
        self::assertSame('Roy Hillis personal diary', HomePageController::BANNER);
    }

    /**
     * Requirement 5.1: the structured question set - mood rating, sleep
     * quality, notable events, thoughts, emotions - in that order, with
     * their exact labels.
     */
    public function testStructuredQuestionSet(): void
    {
        self::assertSame(
            [
                QuestionSet::MOOD_RATING,
                QuestionSet::SLEEP_QUALITY,
                QuestionSet::EVENTS,
                QuestionSet::THOUGHTS,
                QuestionSet::EMOTIONS,
            ],
            QuestionSet::fields()
        );

        $labels = array_map(
            static fn ($question) => $question->label(),
            QuestionSet::definitions()
        );

        self::assertSame(
            ['Mood rating', 'Sleep quality', 'Notable events', 'Thoughts', 'Emotions'],
            $labels
        );
    }

    /** Requirement 1.2. */
    public function testEmailAlreadyRegistered(): void
    {
        self::assertSame('That email address is already registered', AuthService::EMAIL_TAKEN_MESSAGE);
    }

    /** Requirement 1.3. */
    public function testPasswordPolicyDescription(): void
    {
        self::assertSame(
            'Your password must be at least 12 characters long and include at least one letter and at least one digit.',
            DefaultPasswordPolicy::MESSAGE
        );
    }

    /** Requirement 2.2. */
    public function testIncorrectCredentials(): void
    {
        self::assertSame('Your email address or password is incorrect', AuthService::INCORRECT_CREDENTIALS_MESSAGE);
    }

    /** Requirement 2.3. */
    public function testAccountLocked(): void
    {
        self::assertSame(
            'Too many failed sign-in attempts. Please try again in 15 minutes',
            AuthService::ACCOUNT_LOCKED_MESSAGE
        );
    }

    /** Requirement 2.5. */
    public function testSessionEnded(): void
    {
        self::assertSame('Your session ended. Please sign in again', AuthService::SESSION_ENDED_MESSAGE);
        self::assertSame(AuthService::SESSION_ENDED_MESSAGE, AuthService::sessionEnded()->message());
        self::assertTrue(AuthService::sessionEnded()->hasErrorCode(AuthService::SESSION_ENDED_ERROR_CODE));
    }

    /** Requirement 3.3. */
    public function testReadOnlyMessage(): void
    {
        self::assertSame('This account has read-only access', Decision::READ_ONLY_MESSAGE);
        self::assertSame(Decision::READ_ONLY_MESSAGE, AccessControlService::READ_ONLY_MESSAGE);
    }

    /** Requirement 5.5. */
    public function testMoodRatingMissing(): void
    {
        self::assertSame('Please give a mood rating from 1 to 10', DiaryInputValidator::MOOD_RATING_MESSAGE);
    }

    /** Requirement 6.5. */
    public function testFeedbackUnavailable(): void
    {
        self::assertSame('Feedback is temporarily unavailable', FeedbackOutcome::UNAVAILABLE_MESSAGE);
        self::assertSame(FeedbackOutcome::UNAVAILABLE_MESSAGE, FeedbackOutcome::unavailable()->reason());
    }

    /** Requirement 9.4. */
    public function testSummaryInsufficientData(): void
    {
        self::assertSame(
            'More entries are needed to produce a reliable summary',
            SummaryOutcome::INSUFFICIENT_DATA_MESSAGE
        );
        self::assertSame(SummaryOutcome::INSUFFICIENT_DATA_MESSAGE, SummaryOutcome::insufficientData()->reason());
    }

    /** Requirement 9.5. */
    public function testSummaryUnavailable(): void
    {
        self::assertSame('The summary is temporarily unavailable', SummaryOutcome::UNAVAILABLE_MESSAGE);
        self::assertSame(SummaryOutcome::UNAVAILABLE_MESSAGE, SummaryOutcome::unavailable()->reason());
    }

    /** Requirement 10.4. */
    public function testMilestoneFieldMissingMessages(): void
    {
        self::assertSame(
            'Please provide a description for this milestone.',
            MilestoneInputValidator::DESCRIPTION_MESSAGE
        );
        self::assertSame(
            'Please provide a valid date for this milestone.',
            MilestoneInputValidator::DATE_MESSAGE
        );
    }
}
