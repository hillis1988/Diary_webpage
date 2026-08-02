<?php

declare(strict_types=1);

namespace Diary\Tests\Integration;

use Diary\Access\AccessControlService;
use Diary\Access\OwnerId;
use Diary\Access\ViewerAccessService;
use Diary\Ai\AiConfig;
use Diary\Ai\AiFeedbackService;
use Diary\Ai\AiSummaryService;
use Diary\Ai\CbtRecommendation;
use Diary\Ai\CbtRecommendationRepository;
use Diary\Ai\ProgressSummary;
use Diary\Ai\SeriesStats;
use Diary\Ai\TrendDirection;
use Diary\Ai\TrendMetrics;
use Diary\Auth\AuditLogRepository;
use Diary\Auth\AuthService;
use Diary\Auth\DefaultPasswordPolicy;
use Diary\Auth\IpHasher;
use Diary\Auth\PasswordHasher;
use Diary\Auth\SessionCookie;
use Diary\Auth\SessionRepository;
use Diary\Auth\UserId;
use Diary\Auth\UserRepository;
use Diary\Diary\CalendarService;
use Diary\Diary\DiaryEntryRepository;
use Diary\Diary\DiaryInputValidator;
use Diary\Diary\DiaryService;
use Diary\Diary\QuestionSet;
use Diary\Http\AuthorisationMiddleware;
use Diary\Http\CalendarController;
use Diary\Http\CsrfGuard;
use Diary\Http\CsrfMiddleware;
use Diary\Http\DiaryEntryController;
use Diary\Http\FeedbackView;
use Diary\Http\HttpsRedirectMiddleware;
use Diary\Http\MilestoneController;
use Diary\Http\Pipeline;
use Diary\Http\Request;
use Diary\Http\Response;
use Diary\Http\Router;
use Diary\Http\SecurityHeadersMiddleware;
use Diary\Http\SessionResolverMiddleware;
use Diary\Http\SummaryController;
use Diary\Http\ViewerManagementController;
use Diary\Milestone\MilestoneCategory;
use Diary\Milestone\MilestoneInputValidator;
use Diary\Milestone\MilestoneRepository;
use Diary\Milestone\MilestoneService;
use Diary\Milestone\MilestoneSubmission;
use Diary\Storage\Crypto;
use Diary\Storage\KeyRing;
use Diary\Storage\PayloadCodec;
use Diary\Storage\PurgeJobRepository;
use Diary\Storage\PurgeService;
use Diary\Support\FixedClock;
use Diary\Support\LocalDate;
use Diary\Support\Ulid;
use Diary\Tests\Unit\Ai\FakeFeedbackProvider;
use Diary\Tests\Unit\Ai\FakeSummaryProvider;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;

/**
 * Task 18.3: the capstone end-to-end journey, proving every feature works
 * together against a real MariaDB schema, migrated for real, with every
 * request routed through the real HTTP pipeline (HTTPS guard, security
 * headers, CSRF, session resolution, authorisation, router, controller).
 *
 * Only the AI providers are stubbed ({@see FakeFeedbackProvider},
 * {@see FakeSummaryProvider} in place of {@see \Diary\Ai\HttpsFeedbackProvider}
 * and {@see \Diary\Ai\HttpsSummaryProvider}), so this test never makes a
 * network call, matching this project's rule that no test touches the network.
 *
 * There is no controller yet for signing in, accepting a viewer invitation, or
 * deleting an account (see {@see \Diary\Access\AccessControlService} and
 * {@see \Diary\Http\ViewerManagementController}'s class docs); those steps are
 * driven directly against {@see AuthService} and {@see PurgeService}. Every
 * step that does have a controller - diary entry, milestone, calendar,
 * summary, viewer management - goes through the real {@see Pipeline} and
 * {@see Router}, dispatching real {@see Request} objects, exactly as
 * `public/index.php` wires them.
 *
 * Requirements: 1.1, 2.1, 5.3, 5.4, 6.1, 8.1, 9.1, 7.1, 7.4, 4.5.
 */
final class EndToEndJourneysTest extends TestCase
{
    private const OWNER_EMAIL = 'owner@example.com';
    private const OWNER_PASSWORD = 'OwnerPassw0rd123';
    private const VIEWER_EMAIL = 'viewer@example.com';
    private const VIEWER_PASSWORD = 'ViewerPassw0rd123';

    private MariaDbTestSchema $schema;
    private PDO $pdo;
    private FixedClock $clock;

    private AuthService $authService;
    private AccessControlService $accessControl;
    private UserRepository $userRepository;
    private SessionRepository $sessionRepository;
    private DiaryEntryRepository $diaryEntryRepository;
    private ViewerAccessService $viewerAccessService;
    private PurgeService $purgeService;
    private KeyRing $keyRing;
    private CsrfGuard $csrfGuard;
    private FakeFeedbackProvider $feedbackProvider;
    private FakeSummaryProvider $summaryProvider;
    private Pipeline $pipeline;

    protected function setUp(): void
    {
        $reason = MariaDbTestSchema::unavailableReason();

        if ($reason !== null) {
            self::markTestSkipped($reason);
        }

        $schema = MariaDbTestSchema::fromEnvironment();
        self::assertNotNull($schema);
        $this->schema = $schema;
        $this->pdo = $schema->create();

        (new \Diary\Storage\MigrationRunner($this->pdo, FixedClock::at('2024-01-01 00:00:00')))
            ->migrateDirectory(dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'migrations');

        $this->clock = FixedClock::at('2024-06-10 09:00:00');
        $this->buildApplication();
    }

    protected function tearDown(): void
    {
        if (isset($this->schema)) {
            $this->schema->drop();
        }
    }

    /**
     * The full journey: register, sign in, submit entries, view AI feedback,
     * add a milestone, browse the calendar, generate a summary, invite and
     * revoke a viewer, then delete the account.
     */
    public function testFullOwnerJourneyWithAViewerAndAccountDeletion(): void
    {
        // --- Register (Requirement 1.1) ---------------------------------
        $registration = $this->authService->register(self::OWNER_EMAIL, self::OWNER_PASSWORD);
        self::assertTrue($registration->isOk(), 'registration should succeed for a fresh, valid account');
        $ownerId = $registration->value();
        self::assertInstanceOf(UserId::class, $ownerId);
        $ownerOwnerId = OwnerId::fromUserId($ownerId);

        // --- Sign in (Requirement 2.1), establishing a real session row -
        $ownerToken = $this->signIn(self::OWNER_EMAIL, self::OWNER_PASSWORD);

        // --- Submit three diary entries through the real pipeline -------
        // (Requirements 5.3, 5.4, 6.1): each accepted submission triggers the
        // stubbed feedback provider, and its recommendation is rendered back.
        $entries = [
            ['2024-06-01', 'Positive focus for June 1', 'A small change for June 1'],
            ['2024-06-02', 'Positive focus for June 2', 'A small change for June 2'],
            ['2024-06-03', 'Positive focus for June 3', 'A small change for June 3'],
        ];

        $entryIds = [];

        foreach ($entries as [$date, $focus, $change]) {
            $this->feedbackProvider->queue(new CbtRecommendation($focus, $change));

            $response = $this->post(AccessControlService::DIARY_ENTRY_PATH, $ownerToken, [
                QuestionSet::DATE_FIELD => $date,
                QuestionSet::MOOD_RATING => '7',
                QuestionSet::SLEEP_QUALITY => '4',
                QuestionSet::EVENTS => 'A notable event on ' . $date,
                QuestionSet::THOUGHTS => 'Some thoughts on ' . $date,
                QuestionSet::EMOTIONS => 'Calm',
            ]);

            self::assertSame(200, $response->status(), 'a valid diary submission is redisplayed with status 200');
            self::assertStringContainsString(DiaryEntryController::SAVED_MESSAGE, $response->body());
            self::assertStringContainsString($focus, $response->body(), 'the stubbed recommendation must render');
            self::assertStringContainsString($change, $response->body());

            $storedEntry = $this->diaryEntryRepository->findByDate($ownerOwnerId, LocalDate::fromString($date));
            self::assertNotNull($storedEntry, 'the entry must actually be stored');
            $entryIds[] = $storedEntry->id();
        }

        // --- Add a milestone via /milestones/new (Requirement 10.1) -----
        $milestoneResponse = $this->post(MilestoneController::NEW_PATH, $ownerToken, [
            MilestoneSubmission::DATE_FIELD => '2024-06-05',
            MilestoneSubmission::DESCRIPTION_FIELD => 'Started a new morning routine',
            MilestoneSubmission::CATEGORY_FIELD => MilestoneCategory::Lifestyle->value,
        ]);
        self::assertSame(302, $milestoneResponse->status());
        self::assertSame(AccessControlService::MILESTONES_PATH, $milestoneResponse->header('Location'));

        // --- Browse the calendar (Requirement 8.1) ----------------------
        $calendarResponse = $this->get(AccessControlService::CALENDAR_PATH, $ownerToken, [
            CalendarController::MONTH_PARAM => '2024-06',
        ]);
        self::assertSame(200, $calendarResponse->status());
        self::assertSame(3, substr_count($calendarResponse->body(), 'entry-indicator'), 'three entry indicators expected');
        self::assertSame(1, substr_count($calendarResponse->body(), 'milestone-indicator'), 'one milestone indicator expected');

        // Selecting an entry date shows the entry and its recommendation.
        $calendarDetailResponse = $this->get(AccessControlService::CALENDAR_PATH, $ownerToken, [
            CalendarController::MONTH_PARAM => '2024-06',
            CalendarController::DATE_PARAM => '2024-06-01',
        ]);
        self::assertSame(200, $calendarDetailResponse->status());
        self::assertStringContainsString('Positive focus for June 1', $calendarDetailResponse->body());

        // --- Generate a summary (Requirement 9.1), 3+ entries submitted -
        $this->summaryProvider->queue($this->fakeSummary('Overall, mood has been stable this period.', 3));

        $summaryResponse = $this->get(AccessControlService::SUMMARY_PATH, $ownerToken, [
            SummaryController::START_PARAM => '2024-06-01',
            SummaryController::END_PARAM => '2024-06-10',
        ]);
        self::assertSame(200, $summaryResponse->status());
        self::assertStringContainsString('Overall, mood has been stable this period.', $summaryResponse->body());
        self::assertStringContainsString(FeedbackView::DISCLAIMER_MESSAGE, $summaryResponse->body());

        // --- Invite a viewer via /viewers/invite (Requirement 7.1) ------
        $inviteResponse = $this->post(ViewerManagementController::INVITE_PATH, $ownerToken, [
            ViewerManagementController::EMAIL_FIELD => self::VIEWER_EMAIL,
        ]);
        self::assertSame(200, $inviteResponse->status());
        self::assertSame(
            1,
            preg_match('/token=([0-9a-f]{64})/', $inviteResponse->body(), $matches),
            'the invitation link must carry a 64-hex-character token'
        );
        $invitationToken = $matches[1];

        // No controller yet accepts an invitation; drive AuthService directly.
        $acceptance = $this->authService->acceptViewerInvitation(
            $invitationToken,
            self::VIEWER_PASSWORD,
            $this->clock->now(),
        );
        self::assertTrue($acceptance->isOk(), 'the viewer should be able to accept a freshly issued invitation');

        // --- Sign in as the viewer --------------------------------------
        $viewerToken = $this->signIn(self::VIEWER_EMAIL, self::VIEWER_PASSWORD);

        $viewerAccount = $this->userRepository->findByEmail(self::VIEWER_EMAIL);
        self::assertNotNull($viewerAccount);
        $viewerId = $viewerAccount->id;

        // --- Viewer read access (Requirement 7.2) -----------------------
        $viewerCalendarResponse = $this->get(AccessControlService::CALENDAR_PATH, $viewerToken, [
            CalendarController::MONTH_PARAM => '2024-06',
        ]);
        self::assertSame(200, $viewerCalendarResponse->status(), 'a viewer may read the calendar');

        $this->summaryProvider->queue($this->fakeSummary('Viewer-visible summary narrative.', 3));
        $viewerSummaryResponse = $this->get(AccessControlService::SUMMARY_PATH, $viewerToken, [
            SummaryController::START_PARAM => '2024-06-01',
            SummaryController::END_PARAM => '2024-06-10',
        ]);
        self::assertSame(200, $viewerSummaryResponse->status(), 'a viewer may read the summary');

        // --- Viewer denial on mutation (Requirements 3.3, 5.6, 7.3, 10.5) -
        $viewerDiaryAttempt = $this->post(AccessControlService::DIARY_ENTRY_PATH, $viewerToken, [
            QuestionSet::DATE_FIELD => '2024-06-09',
            QuestionSet::MOOD_RATING => '5',
        ]);
        self::assertSame(403, $viewerDiaryAttempt->status(), 'a viewer context can never write a diary entry');
        self::assertStringContainsString(AccessControlService::READ_ONLY_MESSAGE, $viewerDiaryAttempt->body());

        $viewerMilestoneAttempt = $this->post(MilestoneController::NEW_PATH, $viewerToken, [
            MilestoneSubmission::DATE_FIELD => '2024-06-09',
            MilestoneSubmission::DESCRIPTION_FIELD => 'Should never be written',
            MilestoneSubmission::CATEGORY_FIELD => MilestoneCategory::Other->value,
        ]);
        self::assertSame(403, $viewerMilestoneAttempt->status(), 'a viewer context can never write a milestone');

        // --- Revoke the viewer via /viewers/{id}/revoke (Requirement 7.4) -
        $revokeResponse = $this->post(
            AccessControlService::VIEWERS_PATH . '/' . $viewerId->toString() . '/revoke',
            $ownerToken,
            [],
        );
        self::assertSame(302, $revokeResponse->status());
        self::assertSame(AccessControlService::VIEWERS_PATH, $revokeResponse->header('Location'));

        // The viewer's session is terminated immediately: the same cookie no
        // longer resolves to anything, so a protected read redirects to login.
        $afterRevokeResponse = $this->get(AccessControlService::CALENDAR_PATH, $viewerToken, [
            CalendarController::MONTH_PARAM => '2024-06',
        ]);
        self::assertSame(302, $afterRevokeResponse->status());
        self::assertStringStartsWith('/login', (string) $afterRevokeResponse->header('Location'));

        // --- Delete the account (Requirement 4.5) -----------------------
        // No controller yet deletes an account; drive PurgeService directly.
        $deletion = $this->purgeService->requestDeletion($ownerId, $this->clock);
        self::assertTrue($deletion->isOk());

        self::assertSame(0, $this->countRows('users', 'id', $ownerId->toString()), 'the owner row must be gone');
        self::assertSame(0, $this->countRows('users', 'id', $viewerId->toString()), 'the linked viewer row must be gone');
        self::assertSame(0, $this->countRows('diary_entries', 'owner_id', $ownerId->toString()), 'every entry must be gone');
        self::assertSame(0, $this->countRows('milestones', 'owner_id', $ownerId->toString()), 'every milestone must be gone');
        self::assertSame(0, $this->countRows('sessions', 'user_id', $ownerId->toString()), 'the owner session must be gone');
        self::assertSame(0, $this->countRows('sessions', 'user_id', $viewerId->toString()), 'the viewer session must be gone');

        foreach ($entryIds as $entryId) {
            self::assertSame(0, $this->countRows('cbt_recommendations', 'entry_id', $entryId), 'every recommendation must be gone');
        }
    }

    /**
     * The unique `(owner_id, entry_date)` index (migrations/004) is what
     * actually backs "exactly one entry per date" - not merely the
     * application's locking-read upsert logic. A raw INSERT that duplicates
     * an existing (owner_id, entry_date) pair is rejected by MariaDB itself,
     * independent of whether DiaryEntryRepository::upsert()'s locking read
     * ever runs.
     */
    public function testTheDatabaseUniqueIndexRejectsADuplicateOwnerAndDateInsert(): void
    {
        $registration = $this->authService->register('concurrency-owner@example.com', 'ConcurrencyPass1');
        self::assertTrue($registration->isOk());
        $ownerId = $registration->value();

        $keyId = $this->keyRing->createKey();
        $entryDate = '2024-07-01';

        $insert = $this->pdo->prepare(
            'INSERT INTO diary_entries (id, owner_id, entry_date, key_id, nonce, payload_ciphertext, created_at, updated_at)
             VALUES (:id, :owner_id, :entry_date, :key_id, :nonce, :payload_ciphertext, :created_at, :updated_at)'
        );

        $bind = static function (\PDOStatement $statement, string $id) use ($ownerId, $entryDate, $keyId): void {
            $statement->bindValue(':id', $id);
            $statement->bindValue(':owner_id', $ownerId->toString());
            $statement->bindValue(':entry_date', $entryDate);
            $statement->bindValue(':key_id', $keyId);
            $statement->bindValue(':nonce', random_bytes(12), PDO::PARAM_LOB);
            $statement->bindValue(':payload_ciphertext', random_bytes(32), PDO::PARAM_LOB);
            $statement->bindValue(':created_at', '2024-07-01 09:00:00');
            $statement->bindValue(':updated_at', '2024-07-01 09:00:00');
        };

        $bind($insert, Ulid::generate($this->clock));
        $insert->execute();

        self::assertSame(
            1,
            $this->countRows('diary_entries', 'owner_id', $ownerId->toString()),
            'the first insert for this owner and date should succeed'
        );

        // A second, otherwise well-formed row for the same (owner_id, entry_date)
        // pair - simulating what a race between two concurrent submissions would
        // attempt - must be rejected by the unique index itself.
        $secondInsert = $this->pdo->prepare(
            'INSERT INTO diary_entries (id, owner_id, entry_date, key_id, nonce, payload_ciphertext, created_at, updated_at)
             VALUES (:id, :owner_id, :entry_date, :key_id, :nonce, :payload_ciphertext, :created_at, :updated_at)'
        );
        $bind($secondInsert, Ulid::generate($this->clock));

        try {
            $secondInsert->execute();
            self::fail('MariaDB should have rejected the duplicate (owner_id, entry_date) row.');
        } catch (PDOException $exception) {
            self::assertSame('23000', $exception->getCode(), 'a unique-index violation is SQLSTATE 23000');
        }

        self::assertSame(
            1,
            $this->countRows('diary_entries', 'owner_id', $ownerId->toString()),
            'exactly one row must exist for this owner and date after the rejected insert'
        );
    }

    /**
     * Builds the whole object graph exactly as `public/index.php` does,
     * except the AI providers are {@see FakeFeedbackProvider} and
     * {@see FakeSummaryProvider} in place of the real HTTPS adapters, so no
     * network call is ever made.
     */
    private function buildApplication(): void
    {
        $masterKey = random_bytes(32);

        $this->userRepository = new UserRepository($this->pdo);
        $this->sessionRepository = new SessionRepository($this->pdo);
        $ipHasher = new IpHasher('end-to-end-test-ip-hash-key');

        $this->authService = new AuthService(
            users: $this->userRepository,
            passwordPolicy: new DefaultPasswordPolicy(),
            clock: $this->clock,
            passwordHasher: PasswordHasher::forTests(),
            sessions: $this->sessionRepository,
            auditLog: new AuditLogRepository($this->pdo),
            ipHasher: $ipHasher,
        );

        $this->accessControl = new AccessControlService(
            clock: $this->clock,
            auditLog: new AuditLogRepository($this->pdo),
            ipHasher: $ipHasher,
        );

        $this->csrfGuard = new CsrfGuard(str_repeat('k', 32), $this->clock);

        $this->keyRing = new KeyRing($this->pdo, $masterKey, $this->clock);
        $payloadCodec = new PayloadCodec(new Crypto($this->keyRing));

        $this->diaryEntryRepository = new DiaryEntryRepository($this->pdo, $payloadCodec);
        $diaryService = new DiaryService($this->diaryEntryRepository);

        $this->feedbackProvider = new FakeFeedbackProvider();
        $aiConfig = AiConfig::fromConfig(['ai' => ['enabled' => true, 'provider' => 'fake', 'model' => 'fake-model']]);
        $aiFeedbackService = new AiFeedbackService(
            $this->feedbackProvider,
            new CbtRecommendationRepository($this->pdo, $payloadCodec),
            $aiConfig,
        );

        $milestoneRepository = new MilestoneRepository($this->pdo, $payloadCodec);
        $milestoneService = new MilestoneService($milestoneRepository);

        $this->viewerAccessService = new ViewerAccessService(
            $this->userRepository,
            $this->sessionRepository,
            new AuditLogRepository($this->pdo),
        );

        $cbtRecommendationRepository = new CbtRecommendationRepository($this->pdo, $payloadCodec);
        $calendarService = new CalendarService($this->diaryEntryRepository, $milestoneRepository);

        $this->summaryProvider = new FakeSummaryProvider();
        $aiSummaryService = new AiSummaryService($diaryService, $milestoneService, $this->summaryProvider);

        $this->purgeService = new PurgeService($this->pdo, new PurgeJobRepository($this->pdo), $this->clock);

        $router = new Router();

        $diaryEntryController = new DiaryEntryController(
            $this->accessControl,
            $diaryService,
            new DiaryInputValidator(),
            $this->csrfGuard,
            $this->clock,
            $aiFeedbackService,
        );
        $router->get(AccessControlService::DIARY_ENTRY_PATH, static fn (Request $r, array $p) => $diaryEntryController->show($r));
        $router->post(AccessControlService::DIARY_ENTRY_PATH, static fn (Request $r, array $p) => $diaryEntryController->submit($r));
        $router->post(DiaryEntryController::RETRY_FEEDBACK_PATH, static fn (Request $r, array $p) => $diaryEntryController->retryFeedback($r));

        $milestoneController = new MilestoneController(
            $this->accessControl,
            $milestoneService,
            new MilestoneInputValidator(),
            $this->csrfGuard,
            $this->clock,
        );
        $router->get(AccessControlService::MILESTONES_PATH, static fn (Request $r, array $p) => $milestoneController->list($r));
        $router->get(MilestoneController::NEW_PATH, static fn (Request $r, array $p) => $milestoneController->showCreateForm($r));
        $router->post(MilestoneController::NEW_PATH, static fn (Request $r, array $p) => $milestoneController->submitCreate($r));
        $router->get(AccessControlService::MILESTONES_PATH . '/{id}/edit', static fn (Request $r, array $p) => $milestoneController->showEditForm($r, $p));
        $router->post(AccessControlService::MILESTONES_PATH . '/{id}/edit', static fn (Request $r, array $p) => $milestoneController->submitEdit($r, $p));
        $router->post(AccessControlService::MILESTONES_PATH . '/{id}/delete', static fn (Request $r, array $p) => $milestoneController->delete($r, $p));

        $viewerManagementController = new ViewerManagementController(
            $this->accessControl,
            $this->viewerAccessService,
            $this->csrfGuard,
            $this->clock,
        );
        $router->get(AccessControlService::VIEWERS_PATH, static fn (Request $r, array $p) => $viewerManagementController->list($r));
        $router->post(ViewerManagementController::INVITE_PATH, static fn (Request $r, array $p) => $viewerManagementController->invite($r));
        $router->post(AccessControlService::VIEWERS_PATH . '/{id}/revoke', static fn (Request $r, array $p) => $viewerManagementController->revoke($r, $p));

        $calendarController = new CalendarController(
            $this->accessControl,
            $calendarService,
            $diaryService,
            $cbtRecommendationRepository,
            $this->clock,
        );
        $router->get(AccessControlService::CALENDAR_PATH, static fn (Request $r, array $p) => $calendarController->show($r));

        $summaryController = new SummaryController($this->accessControl, $aiSummaryService, $this->clock);
        $router->get(AccessControlService::SUMMARY_PATH, static fn (Request $r, array $p) => $summaryController->show($r));

        $this->pipeline = Pipeline::fixedOrder(
            new HttpsRedirectMiddleware('https://example.test'),
            new SecurityHeadersMiddleware(),
            new CsrfMiddleware($this->csrfGuard),
            new SessionResolverMiddleware($this->authService, $this->clock),
            new AuthorisationMiddleware($this->accessControl),
            $router,
        );
    }

    private function signIn(string $email, string $password): string
    {
        $result = $this->authService->authenticate($email, $password, $this->clock->now());
        self::assertTrue($result->isOk(), 'sign-in should succeed with a correct email and password');

        $session = $result->value();
        $token = $session->issuedToken();
        self::assertNotNull($token);

        return $token->value();
    }

    /**
     * @param array<string, string> $query
     */
    private function get(string $path, ?string $sessionToken, array $query = []): Response
    {
        $cookies = $sessionToken !== null ? [SessionCookie::NAME => $sessionToken] : [];

        return $this->pipeline->handle(Request::of('GET', $path, true, $query, [], $cookies));
    }

    /**
     * A real POST dispatched through the whole pipeline, with a fresh CSRF
     * token minted for the same session cookie so the request is not itself
     * refused by the CSRF stage.
     *
     * @param array<string, string> $form
     */
    private function post(string $path, ?string $sessionToken, array $form): Response
    {
        $cookies = $sessionToken !== null ? [SessionCookie::NAME => $sessionToken] : [];
        $csrfToken = $this->csrfGuard->issueFor(Request::of('GET', $path, true, [], [], $cookies));
        $form[CsrfGuard::FIELD_NAME] = $csrfToken;

        return $this->pipeline->handle(Request::of('POST', $path, true, [], $form, $cookies));
    }

    private function fakeSummary(string $narrative, int $entryCount): ProgressSummary
    {
        $mood = SeriesStats::of($entryCount, 7.0, 6, 8, TrendDirection::Stable);
        $sleep = SeriesStats::of($entryCount, 4.0, 3, 5, TrendDirection::Stable);

        return new ProgressSummary($narrative, TrendMetrics::of($entryCount, $mood, $sleep));
    }

    private function countRows(string $table, string $column, string $value): int
    {
        $statement = $this->pdo->prepare(sprintf('SELECT COUNT(*) FROM %s WHERE %s = :value', $table, $column));
        $statement->bindValue(':value', $value);
        $statement->execute();

        return (int) $statement->fetchColumn();
    }
}
