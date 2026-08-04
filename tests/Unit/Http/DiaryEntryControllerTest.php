<?php

declare(strict_types=1);

namespace Diary\Tests\Unit\Http;

use Diary\Access\AccessControlService;
use Diary\Ai\AiConfig;
use Diary\Ai\AiFeedbackService;
use Diary\Ai\CbtRecommendation;
use Diary\Ai\CbtRecommendationRepository;
use Diary\Ai\ProviderError;
use Diary\Auth\SecurityContext;
use Diary\Auth\Session;
use Diary\Auth\SessionId;
use Diary\Auth\UserId;
use Diary\Auth\UserRole;
use Diary\Diary\DiaryEntryRepository;
use Diary\Diary\DiaryInputValidator;
use Diary\Diary\DiaryService;
use Diary\Diary\QuestionSet;
use Diary\Http\CsrfGuard;
use Diary\Http\DiaryEntryController;
use Diary\Http\FeedbackView;
use Diary\Http\Request;
use Diary\Http\SessionResolverMiddleware;
use Diary\Storage\ConnectionFactory;
use Diary\Storage\Crypto;
use Diary\Storage\KeyRing;
use Diary\Storage\PayloadCodec;
use Diary\Support\FixedClock;
use Diary\Support\Ulid;
use Diary\Tests\Unit\Ai\FakeFeedbackProvider;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * The diary entry page and controller (Requirements 5.1, 5.3, 5.6, 8.2).
 *
 * Uses an in-memory SQLite schema standing in for migrations 003 and 004,
 * following the pattern in tests/Unit/Diary/DiaryEntryRepositoryTest.php.
 */
final class DiaryEntryControllerTest extends TestCase
{
    private PDO $pdo;
    private FixedClock $clock;
    private AccessControlService $access;
    private DiaryEntryController $controller;
    private CsrfGuard $csrf;
    private FakeFeedbackProvider $feedbackProvider;

    protected function setUp(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_sqlite is not loaded.');
        }

        $this->pdo = ConnectionFactory::fromDsn('sqlite::memory:');

        $this->pdo->exec(
            'CREATE TABLE encryption_keys (
                id          CHAR(26)   NOT NULL PRIMARY KEY,
                wrapped_dek BLOB       NOT NULL,
                wrap_nonce  BLOB       NOT NULL,
                created_at  DATETIME   NOT NULL,
                retired_at  DATETIME   NULL
            )'
        );

        $this->pdo->exec(
            'CREATE TABLE diary_entries (
                id                 CHAR(26)   NOT NULL PRIMARY KEY,
                owner_id           CHAR(26)   NOT NULL,
                entry_date         DATE       NOT NULL,
                key_id             CHAR(26)   NOT NULL,
                nonce              BLOB       NOT NULL,
                payload_ciphertext BLOB       NOT NULL,
                created_at         DATETIME   NOT NULL,
                updated_at         DATETIME   NOT NULL,
                UNIQUE (owner_id, entry_date)
            )'
        );

        $this->pdo->exec(
            'CREATE TABLE cbt_recommendations (
                id                 CHAR(26)   NOT NULL PRIMARY KEY,
                entry_id           CHAR(26)   NOT NULL,
                status             TEXT       NOT NULL,
                key_id             CHAR(26)   NULL,
                nonce              BLOB       NULL,
                payload_ciphertext BLOB       NULL,
                provider           TEXT       NULL,
                model              TEXT       NULL,
                attempt_count      INTEGER    NOT NULL DEFAULT 0,
                generated_at       DATETIME   NULL,
                UNIQUE (entry_id)
            )'
        );

        $this->clock = FixedClock::at('2025-03-15 09:00:00');

        $keyRing = new KeyRing($this->pdo, str_repeat("\x2a", KeyRing::KEY_LENGTH), $this->clock);
        $codec = new PayloadCodec(new Crypto($keyRing));
        $repository = new DiaryEntryRepository($this->pdo, $codec);
        $diaryService = new DiaryService($repository);

        $this->access = new AccessControlService($this->clock);
        $this->csrf = CsrfGuard::withMasterKey(str_repeat("\x2b", 32), $this->clock);

        $this->feedbackProvider = new FakeFeedbackProvider();
        $aiConfig = AiConfig::fromConfig(['ai' => [
            'enabled' => true,
            'provider' => 'example-provider',
            'endpoint' => 'https://api.example.com/v1/chat/completions',
            'api_key' => 'secret-key',
            'model' => 'example-model',
            'timeout_seconds' => 20,
            'retries' => 1,
        ]]);
        $aiFeedbackService = new AiFeedbackService(
            $this->feedbackProvider,
            new CbtRecommendationRepository($this->pdo, $codec),
            $aiConfig,
        );

        $this->controller = new DiaryEntryController(
            $this->access,
            $diaryService,
            new DiaryInputValidator(),
            $this->csrf,
            $this->clock,
            $aiFeedbackService,
        );
    }

    private function ownerContext(): SecurityContext
    {
        $userId = UserId::fromString(Ulid::generate($this->clock));
        $session = new Session(
            id: SessionId::fromString(str_repeat('a', 64)),
            userId: $userId,
            contextRole: UserRole::Owner,
            dataOwnerId: $userId,
            createdAt: $this->clock->now(),
            lastActivityAt: $this->clock->now(),
        );

        return SecurityContext::forSession($session);
    }

    private function viewerContext(): SecurityContext
    {
        $ownerId = UserId::fromString(Ulid::generate($this->clock));
        $viewerId = UserId::fromString(Ulid::generate($this->clock));
        $session = new Session(
            id: SessionId::fromString(str_repeat('c', 64)),
            userId: $viewerId,
            contextRole: UserRole::Viewer,
            dataOwnerId: $ownerId,
            createdAt: $this->clock->now(),
            lastActivityAt: $this->clock->now(),
        );

        return SecurityContext::forSession($session);
    }

    private function getRequest(SecurityContext $context): Request
    {
        return Request::of('GET', '/diary')
            ->withAttribute(SessionResolverMiddleware::CONTEXT_ATTRIBUTE, $context);
    }

    private function postRequest(SecurityContext $context, array $form): Request
    {
        return Request::of('POST', '/diary', form: $form)
            ->withAttribute(SessionResolverMiddleware::CONTEXT_ATTRIBUTE, $context);
    }

    public function testShowRendersAnAccessibleFormWithLabelsForEveryQuestion(): void
    {
        $response = $this->controller->show($this->getRequest($this->ownerContext()));

        self::assertSame(200, $response->status());
        $html = $response->body();

        self::assertStringContainsString('<form method="post" action="/diary">', $html);
        self::assertStringContainsString('for="' . QuestionSet::MOOD_RATING . '"', $html);
        self::assertStringContainsString('id="' . QuestionSet::MOOD_RATING . '"', $html);
        self::assertStringContainsString('for="' . QuestionSet::EVENTS . '"', $html);
        self::assertStringContainsString('id="' . QuestionSet::EVENTS . '"', $html);
    }

    public function testShowDefaultsTheDateFieldToTodayAndLeavesOtherFieldsBlank(): void
    {
        $html = $this->controller->show($this->getRequest($this->ownerContext()))->body();

        self::assertStringContainsString('value="2025-03-15"', $html);
        // No prior day's values are prefilled: the mood rating placeholder is selected.
        self::assertStringContainsString('<option value="" selected>Select a rating</option>', $html);
    }

    public function testShowIncludesAFreshCsrfToken(): void
    {
        $html = $this->controller->show($this->getRequest($this->ownerContext()))->body();

        self::assertStringContainsString('name="' . CsrfGuard::FIELD_NAME . '"', $html);
    }

    public function testShowInAViewerContextIsDenied(): void
    {
        $response = $this->controller->show($this->getRequest($this->viewerContext()));

        self::assertSame(403, $response->status());
        self::assertStringContainsString('read-only access', $response->body());
    }

    public function testShowAnonymousRedirectsToLogin(): void
    {
        $response = $this->controller->show(Request::of('GET', '/diary'));

        self::assertSame(302, $response->status());
        self::assertStringContainsString('/login', (string) $response->header('Location'));
    }

    public function testSubmitWithAMissingMoodRatingRedisplaysAnswersAndTheErrorMessage(): void
    {
        $context = $this->ownerContext();
        $token = $this->csrf->issueFor($this->getRequest($context));

        $request = $this->postRequest($context, [
            CsrfGuard::FIELD_NAME => $token,
            QuestionSet::DATE_FIELD => '2025-03-10',
            QuestionSet::MOOD_RATING => '',
            QuestionSet::EVENTS => 'Went for a walk',
            QuestionSet::THOUGHTS => 'Calm thoughts',
            QuestionSet::EMOTIONS => 'Content',
        ]);

        $response = $this->controller->submit($request);
        $html = $response->body();

        self::assertSame(200, $response->status());
        self::assertStringContainsString(DiaryInputValidator::MOOD_RATING_MESSAGE, $html);
        // Submitted answers are preserved for redisplay.
        self::assertStringContainsString('value="2025-03-10"', $html);
        self::assertStringContainsString('Went for a walk', $html);
        self::assertStringContainsString('Calm thoughts', $html);
    }

    public function testSubmitWithAValidEntrySavesItAndRedisplaysItWithTheGeneratedRecommendation(): void
    {
        $this->feedbackProvider->queue(new CbtRecommendation('You went for a walk.', 'Try a short walk tomorrow too.'));

        $context = $this->ownerContext();
        $token = $this->csrf->issueFor($this->getRequest($context));

        $request = $this->postRequest($context, [
            CsrfGuard::FIELD_NAME => $token,
            QuestionSet::DATE_FIELD => '2025-03-10',
            QuestionSet::MOOD_RATING => '8',
            QuestionSet::SLEEP_QUALITY => '4',
            QuestionSet::EVENTS => 'Went for a walk',
            QuestionSet::THOUGHTS => 'Calm thoughts',
            QuestionSet::EMOTIONS => 'Content',
        ]);

        $response = $this->controller->submit($request);
        $html = $response->body();

        self::assertSame(200, $response->status());
        self::assertStringContainsString(DiaryEntryController::SAVED_MESSAGE, $html);
        self::assertStringContainsString('2025-03-10', $html);
        self::assertStringContainsString('Went for a walk', $html);
        // The generated recommendation and the medical disclaimer (Requirements 6.5, 6.6).
        self::assertStringContainsString('You went for a walk.', $html);
        self::assertStringContainsString('Try a short walk tomorrow too.', $html);
        self::assertStringContainsString(FeedbackView::DISCLAIMER_MESSAGE, $html);
        // No retry control on a successful outcome.
        self::assertStringNotContainsString(FeedbackView::RETRY_BUTTON_LABEL, $html);

        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) AS c FROM diary_entries')->fetch(PDO::FETCH_ASSOC)['c']);
    }

    public function testSubmitWithAProviderFailureRedisplaysTheEntryWithTheUnavailableNoticeAndARetryControl(): void
    {
        $this->feedbackProvider->queueFailure(new ProviderError('timed out'));

        $context = $this->ownerContext();
        $token = $this->csrf->issueFor($this->getRequest($context));

        $request = $this->postRequest($context, [
            CsrfGuard::FIELD_NAME => $token,
            QuestionSet::DATE_FIELD => '2025-03-10',
            QuestionSet::MOOD_RATING => '8',
            QuestionSet::EVENTS => 'Went for a walk',
        ]);

        $response = $this->controller->submit($request);
        $html = $response->body();

        self::assertSame(200, $response->status());
        self::assertStringContainsString(DiaryEntryController::SAVED_MESSAGE, $html);
        self::assertStringContainsString('Feedback is temporarily unavailable', $html);
        self::assertStringContainsString(FeedbackView::DISCLAIMER_MESSAGE, $html);
        self::assertStringContainsString(DiaryEntryController::RETRY_FEEDBACK_PATH, $html);
        self::assertStringContainsString(FeedbackView::RETRY_BUTTON_LABEL, $html);

        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) AS c FROM diary_entries')->fetch(PDO::FETCH_ASSOC)['c']);
    }

    public function testRetryFeedbackReinvokesTheProviderAndRedisplaysTheSameEntry(): void
    {
        $this->feedbackProvider->queueFailure(new ProviderError('timed out'));

        $context = $this->ownerContext();
        $token = $this->csrf->issueFor($this->getRequest($context));

        $submitRequest = $this->postRequest($context, [
            CsrfGuard::FIELD_NAME => $token,
            QuestionSet::DATE_FIELD => '2025-03-10',
            QuestionSet::MOOD_RATING => '8',
            QuestionSet::EVENTS => 'Went for a walk',
        ]);
        $this->controller->submit($submitRequest);

        $this->feedbackProvider->queue(new CbtRecommendation('You went for a walk.', 'Try a short walk tomorrow too.'));

        $retryRequest = Request::of('POST', DiaryEntryController::RETRY_FEEDBACK_PATH, form: [
            CsrfGuard::FIELD_NAME => $this->csrf->issueFor($this->getRequest($context)),
            DiaryEntryController::RETRY_DATE_FIELD => '2025-03-10',
        ])->withAttribute(SessionResolverMiddleware::CONTEXT_ATTRIBUTE, $context);

        $response = $this->controller->retryFeedback($retryRequest);
        $html = $response->body();

        self::assertSame(200, $response->status());
        self::assertStringContainsString('You went for a walk.', $html);
        self::assertStringContainsString('Try a short walk tomorrow too.', $html);
        self::assertStringContainsString(FeedbackView::DISCLAIMER_MESSAGE, $html);
        self::assertSame(2, $this->feedbackProvider->callCount());
        // The retry never touches diary_entries.
        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) AS c FROM diary_entries')->fetch(PDO::FETCH_ASSOC)['c']);
        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) AS c FROM cbt_recommendations')->fetch(PDO::FETCH_ASSOC)['c']);
    }

    public function testRetryFeedbackInAViewerContextIsDenied(): void
    {
        $context = $this->viewerContext();

        $retryRequest = Request::of('POST', DiaryEntryController::RETRY_FEEDBACK_PATH, form: [
            CsrfGuard::FIELD_NAME => $this->csrf->issueFor($this->getRequest($context)),
            DiaryEntryController::RETRY_DATE_FIELD => '2025-03-10',
        ])->withAttribute(SessionResolverMiddleware::CONTEXT_ATTRIBUTE, $context);

        $response = $this->controller->retryFeedback($retryRequest);

        self::assertSame(403, $response->status());
        self::assertStringContainsString('read-only access', $response->body());
    }

    public function testSecondSubmissionForTheSameDateUpdatesRatherThanDuplicates(): void
    {
        $context = $this->ownerContext();

        $first = $this->postRequest($context, [
            CsrfGuard::FIELD_NAME => $this->csrf->issueFor($this->getRequest($context)),
            QuestionSet::DATE_FIELD => '2025-03-10',
            QuestionSet::MOOD_RATING => '5',
        ]);
        $this->controller->submit($first);

        $this->clock->advanceMinutes(5);

        $second = $this->postRequest($context, [
            CsrfGuard::FIELD_NAME => $this->csrf->issueFor($this->getRequest($context)),
            QuestionSet::DATE_FIELD => '2025-03-10',
            QuestionSet::MOOD_RATING => '9',
        ]);
        $response = $this->controller->submit($second);

        self::assertStringContainsString('value="9"', $response->body());
        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) AS c FROM diary_entries')->fetch(PDO::FETCH_ASSOC)['c']);
    }

    public function testSubmitInAViewerContextIsDeniedAndWritesNothing(): void
    {
        $context = $this->viewerContext();

        $request = $this->postRequest($context, [
            CsrfGuard::FIELD_NAME => $this->csrf->issueFor($this->getRequest($context)),
            QuestionSet::DATE_FIELD => '2025-03-10',
            QuestionSet::MOOD_RATING => '8',
        ]);

        $response = $this->controller->submit($request);

        self::assertSame(403, $response->status());
        self::assertStringContainsString('read-only access', $response->body());
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) AS c FROM diary_entries')->fetch(PDO::FETCH_ASSOC)['c']);
    }

    public function testSubmitAnonymousRedirectsToLoginAndWritesNothing(): void
    {
        $request = Request::of('POST', '/diary', form: [
            QuestionSet::DATE_FIELD => '2025-03-10',
            QuestionSet::MOOD_RATING => '8',
        ]);

        $response = $this->controller->submit($request);

        self::assertSame(302, $response->status());
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) AS c FROM diary_entries')->fetch(PDO::FETCH_ASSOC)['c']);
    }
}
