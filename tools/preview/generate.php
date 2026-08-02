<?php

declare(strict_types=1);

/**
 * Static, database-free preview generator (task 22.5).
 *
 * Calls each controller's existing static render()/renderXxx() method with
 * hand-built sample data (mirroring the fixtures used in
 * tests/Unit/Http/*.php) and writes the resulting HTML to public/preview/.
 *
 * No database, no session, no HTTP routing involved: this script only
 * exercises the pure rendering methods the controllers already expose, so it
 * does not run any new production code path. It exists purely as a dev-only
 * visualisation aid for the pages this application already produces.
 *
 *   php tools/preview/generate.php
 *
 * Exit codes: 0 success, 1 failure (a PHP error/warning while generating a
 * page is treated as a failure).
 */

use Diary\Access\NavigationItem;
use Diary\Access\OwnerId;
use Diary\Ai\CbtRecommendation;
use Diary\Ai\FeedbackOutcome;
use Diary\Ai\ProgressSummary;
use Diary\Ai\SeriesStats;
use Diary\Ai\SummaryOutcome;
use Diary\Ai\TrendDirection;
use Diary\Ai\TrendMetrics;
use Diary\Auth\UserAccount;
use Diary\Auth\UserId;
use Diary\Auth\UserRole;
use Diary\Auth\UserStatus;
use Diary\Diary\DiaryEntry;
use Diary\Diary\DiaryEntryInput;
use Diary\Diary\DiaryInputValidator;
use Diary\Diary\QuestionSet;
use Diary\Diary\SubmittedAnswers;
use Diary\Http\AcceptInvitationController;
use Diary\Http\AccountController;
use Diary\Http\CalendarController;
use Diary\Http\DiaryEntryController;
use Diary\Http\HomePageController;
use Diary\Http\LoginController;
use Diary\Http\MilestoneController;
use Diary\Http\RegistrationController;
use Diary\Http\Router;
use Diary\Http\StatusPage;
use Diary\Http\SummaryController;
use Diary\Http\ViewerManagementController;
use Diary\Milestone\Milestone;
use Diary\Milestone\MilestoneCategory;
use Diary\Milestone\MilestoneId;
use Diary\Milestone\MilestoneInput;
use Diary\Support\DateRange;
use Diary\Support\LocalDate;
use Diary\Support\Ulid;
use Diary\Support\YearMonth;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

require __DIR__ . '/../../vendor/autoload.php';

$outputDir = dirname(__DIR__, 2) . '/public/preview';

if (!is_dir($outputDir) && !mkdir($outputDir, 0777, true) && !is_dir($outputDir)) {
    fwrite(STDERR, sprintf('Could not create output directory "%s".%s', $outputDir, PHP_EOL));
    exit(1);
}

/** @var list<string> $generatedFiles */
$generatedFiles = [];

/**
 * Writes one preview page and records it for the index, failing loudly if
 * anything about the write goes wrong.
 */
$write = static function (string $fileName, string $html) use ($outputDir, &$generatedFiles): void {
    $path = $outputDir . '/' . $fileName;

    if (file_put_contents($path, $html) === false) {
        throw new RuntimeException(sprintf('Failed to write preview page "%s".', $path));
    }

    $generatedFiles[] = $fileName;
};

try {
    // ---------------------------------------------------------------------
    // 1. Home page
    // ---------------------------------------------------------------------
    $ownerNavigation = [
        new NavigationItem('Diary entry', '/diary'),
        new NavigationItem('Calendar', '/calendar'),
        new NavigationItem('Summary', '/summary'),
        new NavigationItem('Milestones', '/milestones'),
        new NavigationItem('Viewers', '/viewers'),
    ];
    $viewerNavigation = [
        new NavigationItem('Calendar', '/calendar'),
        new NavigationItem('Summary', '/summary'),
    ];

    $write('home-owner.html', HomePageController::render($ownerNavigation, 'preview-csrf-token'));
    $write('home-viewer.html', HomePageController::render($viewerNavigation, 'preview-csrf-token'));

    // ---------------------------------------------------------------------
    // 2. Diary entry page
    // ---------------------------------------------------------------------
    $questions = QuestionSet::definitions();

    $ownerId = OwnerId::fromString(Ulid::generate());
    $entryDate = LocalDate::of(2025, 6, 1);
    $entryInput = DiaryEntryInput::of(
        date: $entryDate,
        moodRating: 8,
        sleepQuality: 4,
        events: 'Went for a long walk in the park and had coffee with a friend.',
        thoughts: 'Felt calmer than usual, less racing thoughts.',
        emotions: 'Content, a little tired.',
    );
    $savedEntry = DiaryEntry::of(
        Ulid::generate(),
        $ownerId,
        $entryInput,
        new DateTimeImmutable('2025-06-01 20:00:00'),
        new DateTimeImmutable('2025-06-01 20:00:00'),
    );

    // Blank form.
    $blankAnswers = SubmittedAnswers::blank()->with(QuestionSet::DATE_FIELD, '2025-06-01');
    $write('diary-entry-blank.html', DiaryEntryController::render(
        $questions,
        $blankAnswers,
        null,
        [],
        'preview-csrf-token',
        null,
        null,
    ));

    // Saved entry with a generated recommendation.
    $recommendation = FeedbackOutcome::generated(new CbtRecommendation(
        'You made time for connection and movement today - both support mood.',
        'Tomorrow, try writing down one thing you are looking forward to.',
    ));
    $write('diary-entry-saved.html', DiaryEntryController::render(
        $questions,
        $savedEntry->input()->toSubmittedAnswers(),
        null,
        [],
        'preview-csrf-token',
        $savedEntry,
        $recommendation,
    ));

    // Saved entry with feedback unavailable.
    $write('diary-entry-feedback-unavailable.html', DiaryEntryController::render(
        $questions,
        $savedEntry->input()->toSubmittedAnswers(),
        null,
        [],
        'preview-csrf-token',
        $savedEntry,
        FeedbackOutcome::unavailable(),
    ));

    // Validation error.
    $invalidAnswers = SubmittedAnswers::blank()
        ->with(QuestionSet::DATE_FIELD, '2025-06-01')
        ->with(QuestionSet::EVENTS, 'Went for a walk');
    $write('diary-entry-validation-error.html', DiaryEntryController::render(
        $questions,
        $invalidAnswers,
        DiaryInputValidator::MOOD_RATING_MESSAGE,
        [QuestionSet::MOOD_RATING => DiaryInputValidator::MOOD_RATING_MESSAGE],
        'preview-csrf-token',
        null,
        null,
    ));

    // ---------------------------------------------------------------------
    // 3. Calendar page (render()/renderDateDetail() are private statics)
    // ---------------------------------------------------------------------
    $calendarMonth = \Diary\Diary\CalendarMonth::of(
        YearMonth::of(2025, 6),
        array_map(static fn (int $day): LocalDate => LocalDate::of(2025, 6, $day), [1, 3, 5, 10, 15, 20, 25]),
        array_map(static fn (int $day): LocalDate => LocalDate::of(2025, 6, $day), [5, 18]),
    );

    $calendarRenderMethod = new ReflectionMethod(CalendarController::class, 'render');
    $calendarRenderMethod->setAccessible(true);
    $calendarDetailMethod = new ReflectionMethod(CalendarController::class, 'renderDateDetail');
    $calendarDetailMethod->setAccessible(true);

    $selectedDate = LocalDate::of(2025, 6, 1);
    $calendarRecommendation = \Diary\Ai\CbtRecommendationRecord::generated(
        'preview-recommendation-id',
        $savedEntry->id(),
        new CbtRecommendation(
            'You made time for connection and movement today - both support mood.',
            'Tomorrow, try writing down one thing you are looking forward to.',
        ),
        'preview-provider',
        'preview-model',
        1,
        new DateTimeImmutable('2025-06-01 20:00:00'),
    );
    $detail = $calendarDetailMethod->invoke(null, [$savedEntry, $calendarRecommendation], $selectedDate);
    $calendarHtml = $calendarRenderMethod->invoke(null, $calendarMonth, $selectedDate, $detail);
    $write('calendar.html', $calendarHtml);

    // ---------------------------------------------------------------------
    // 4. Summary page
    // ---------------------------------------------------------------------
    $summaryRange = DateRange::of(LocalDate::of(2025, 5, 1), LocalDate::of(2025, 6, 1));

    $summaryWithMetrics = SummaryOutcome::summary(new ProgressSummary(
        'Your mood has trended upward over the past month, with steadier sleep as well.',
        TrendMetrics::of(
            12,
            SeriesStats::of(12, 6.8, 3, 9, TrendDirection::Improving),
            SeriesStats::of(10, 3.4, 2, 5, TrendDirection::Stable),
        ),
    ));
    $write('summary-with-metrics.html', SummaryController::render($summaryRange, $summaryWithMetrics));
    $write('summary-insufficient-data.html', SummaryController::render($summaryRange, SummaryOutcome::insufficientData()));
    $write('summary-unavailable.html', SummaryController::render($summaryRange, SummaryOutcome::unavailable()));
    $write('summary-picker-only.html', SummaryController::render($summaryRange, null));

    // ---------------------------------------------------------------------
    // 5. Milestones list
    // ---------------------------------------------------------------------
    $milestones = [
        Milestone::of(
            MilestoneId::fromString(Ulid::generate()),
            $ownerId,
            MilestoneInput::of(LocalDate::of(2025, 6, 2), 'Started a new medication dosage.', MilestoneCategory::Medication),
            new DateTimeImmutable('2025-06-02 09:00:00'),
            new DateTimeImmutable('2025-06-02 09:00:00'),
        ),
        Milestone::of(
            MilestoneId::fromString(Ulid::generate()),
            $ownerId,
            MilestoneInput::of(LocalDate::of(2025, 6, 8), 'Had an honest conversation with a close friend.', MilestoneCategory::Relationship),
            new DateTimeImmutable('2025-06-08 18:00:00'),
            new DateTimeImmutable('2025-06-08 18:00:00'),
        ),
        Milestone::of(
            MilestoneId::fromString(Ulid::generate()),
            $ownerId,
            MilestoneInput::of(LocalDate::of(2025, 6, 15), 'Started going for a daily morning walk.', MilestoneCategory::Lifestyle),
            new DateTimeImmutable('2025-06-15 07:30:00'),
            new DateTimeImmutable('2025-06-15 07:30:00'),
        ),
        Milestone::of(
            MilestoneId::fromString(Ulid::generate()),
            $ownerId,
            MilestoneInput::of(LocalDate::of(2025, 6, 22), 'Finished reading a book that helped with perspective.', MilestoneCategory::Other),
            new DateTimeImmutable('2025-06-22 21:00:00'),
            new DateTimeImmutable('2025-06-22 21:00:00'),
        ),
    ];

    $write('milestones-owner.html', MilestoneController::renderList($milestones, true, 'preview-csrf-token'));
    $write('milestones-viewer.html', MilestoneController::renderList($milestones, false, 'preview-csrf-token'));
    $write('milestones-empty.html', MilestoneController::renderList([], true, 'preview-csrf-token'));

    // ---------------------------------------------------------------------
    // 6. Viewers list
    // ---------------------------------------------------------------------
    $viewerActive = new UserAccount(
        id: UserId::fromString(Ulid::generate()),
        emailNormalized: 'jane.viewer@example.com',
        emailDisplay: 'jane.viewer@example.com',
        passwordHash: null,
        role: UserRole::Viewer,
        dataOwnerId: $ownerId->toUserId(),
        status: UserStatus::Active,
        failedLoginCount: 0,
        lockedUntil: null,
        deletionRequestedAt: null,
        createdAt: new DateTimeImmutable('2025-05-01 10:00:00'),
        updatedAt: new DateTimeImmutable('2025-05-01 10:00:00'),
    );
    $viewerInvited = new UserAccount(
        id: UserId::fromString(Ulid::generate()),
        emailNormalized: 'sam.invited@example.com',
        emailDisplay: 'sam.invited@example.com',
        passwordHash: null,
        role: UserRole::Viewer,
        dataOwnerId: $ownerId->toUserId(),
        status: UserStatus::Invited,
        failedLoginCount: 0,
        lockedUntil: null,
        deletionRequestedAt: null,
        createdAt: new DateTimeImmutable('2025-06-01 10:00:00'),
        updatedAt: new DateTimeImmutable('2025-06-01 10:00:00'),
    );
    $viewers = [$viewerActive, $viewerInvited];

    $write('viewers-list.html', ViewerManagementController::renderList(
        $viewers,
        'preview-csrf-token',
        null,
        null,
        '',
    ));
    $write('viewers-invited.html', ViewerManagementController::renderList(
        $viewers,
        'preview-csrf-token',
        null,
        'https://www.royhillis.co.uk/accept-invitation?token=example-token-value',
        '',
    ));
    $write('viewers-empty.html', ViewerManagementController::renderList(
        [],
        'preview-csrf-token',
        null,
        null,
        '',
    ));

    // ---------------------------------------------------------------------
    // 7. Login page
    // ---------------------------------------------------------------------
    $write('login.html', LoginController::render('preview-csrf-token', null, '', null));
    $write('login-error.html', LoginController::render(
        'preview-csrf-token',
        null,
        'jane@example.com',
        \Diary\Auth\AuthService::INCORRECT_CREDENTIALS_MESSAGE,
    ));

    // ---------------------------------------------------------------------
    // 8. Registration page
    // ---------------------------------------------------------------------
    $write('register.html', RegistrationController::render('', null, [], 'preview-csrf-token'));

    // ---------------------------------------------------------------------
    // 9. Accept invitation page
    // ---------------------------------------------------------------------
    $write('accept-invitation.html', AcceptInvitationController::renderForm(
        'example-token-value',
        null,
        [],
        'preview-csrf-token',
    ));
    $write('accept-invitation-invalid.html', AcceptInvitationController::renderInvalidPage());

    // ---------------------------------------------------------------------
    // 10. Account deletion confirmation
    // ---------------------------------------------------------------------
    $write('account-delete-confirm.html', AccountController::renderConfirmation('preview-csrf-token'));

    // ---------------------------------------------------------------------
    // 11. Status page (404 example)
    // ---------------------------------------------------------------------
    $write('status-404.html', StatusPage::render(Router::NOT_FOUND_HEADING, Router::NOT_FOUND_MESSAGE));

    // ---------------------------------------------------------------------
    // Index of every generated page.
    // ---------------------------------------------------------------------
    sort($generatedFiles);
    $links = '';
    foreach ($generatedFiles as $fileName) {
        $safeName = htmlspecialchars($fileName, ENT_QUOTES, 'UTF-8');
        $links .= '<li><a href="' . $safeName . '">' . $safeName . '</a></li>' . "\n";
    }
    $indexHtml = '<!DOCTYPE html>' . "\n"
        . '<html lang="en">' . "\n"
        . '<head><meta charset="utf-8"><title>Preview index</title></head>' . "\n"
        . '<body>' . "\n"
        . '<h1>Preview index</h1>' . "\n"
        . '<ul>' . "\n"
        . $links
        . '</ul>' . "\n"
        . '</body>' . "\n"
        . '</html>' . "\n";
    $write('index.html', $indexHtml);

    fwrite(STDOUT, sprintf('Generated %d preview page(s) in %s%s', count($generatedFiles), $outputDir, PHP_EOL));
    exit(0);
} catch (Throwable $failure) {
    fwrite(STDERR, 'Preview generation failed: ' . $failure->getMessage() . PHP_EOL);
    fwrite(STDERR, $failure->getTraceAsString() . PHP_EOL);
    exit(1);
}
