<?php

declare(strict_types=1);

namespace Diary\Tests\Unit\Http;

use Diary\Access\AccessControlService;
use Diary\Ai\AiSummaryService;
use Diary\Ai\CbtAdvice;
use Diary\Ai\ProgressSummary;
use Diary\Ai\ProviderError;
use Diary\Ai\SeriesStats;
use Diary\Ai\TrendDirection;
use Diary\Ai\TrendMetrics;
use Diary\Auth\SecurityContext;
use Diary\Auth\Session;
use Diary\Auth\SessionId;
use Diary\Auth\UserId;
use Diary\Auth\UserRole;
use Diary\Diary\DiaryEntryInput;
use Diary\Diary\DiaryEntryRepository;
use Diary\Diary\DiaryService;
use Diary\Diary\DiaryValidation;
use Diary\Http\Request;
use Diary\Http\SessionResolverMiddleware;
use Diary\Http\SummaryController;
use Diary\Milestone\MilestoneRepository;
use Diary\Milestone\MilestoneService;
use Diary\Storage\ConnectionFactory;
use Diary\Storage\Crypto;
use Diary\Storage\KeyRing;
use Diary\Storage\PayloadCodec;
use Diary\Support\FixedClock;
use Diary\Support\LocalDate;
use Diary\Support\Ulid;
use Diary\Tests\Unit\Ai\FakeSummaryProvider;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * The summary page controller (Requirements 9.1, 9.2, 9.4, 9.5, 9.6).
 *
 * Uses an in-memory SQLite schema standing in for migrations 004 and 006,
 * following the pattern in tests/Unit/Http/CalendarControllerTest.php and
 * tests/Unit/Ai/AiSummaryServiceTest.php, with a {@see FakeSummaryProvider}
 * injected into a real {@see AiSummaryService} rather than a network call.
 */
final class SummaryControllerTest extends TestCase
{
    private PDO $pdo;
    private FixedClock $clock;
    private AccessControlService $access;
    private DiaryService $diaryService;
    private MilestoneService $milestoneService;
    private FakeSummaryProvider $provider;
    private SummaryController $controller;

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
            'CREATE TABLE milestones (
                id                 CHAR(26)   NOT NULL PRIMARY KEY,
                owner_id           CHAR(26)   NOT NULL,
                milestone_date     DATE       NOT NULL,
                key_id             CHAR(26)   NOT NULL,
                nonce              BLOB       NOT NULL,
                payload_ciphertext BLOB       NOT NULL,
                created_at         DATETIME   NOT NULL,
                updated_at         DATETIME   NOT NULL
            )'
        );

        $this->clock = FixedClock::at('2025-03-15 09:00:00');

        $keyRing = new KeyRing($this->pdo, str_repeat("\x2a", KeyRing::KEY_LENGTH), $this->clock);
        $codec = new PayloadCodec(new Crypto($keyRing));

        $this->diaryService = new DiaryService(new DiaryEntryRepository($this->pdo, $codec));
        $this->milestoneService = new MilestoneService(new MilestoneRepository($this->pdo, $codec));

        $this->access = new AccessControlService($this->clock);
        $this->provider = new FakeSummaryProvider();

        $this->controller = new SummaryController(
            $this->access,
            new AiSummaryService($this->diaryService, $this->milestoneService, $this->provider),
            $this->clock,
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

    private function viewerContextFor(SecurityContext $ownerContext): SecurityContext
    {
        $viewerId = UserId::fromString(Ulid::generate($this->clock));
        $session = new Session(
            id: SessionId::fromString(str_repeat('c', 64)),
            userId: $viewerId,
            contextRole: UserRole::Viewer,
            dataOwnerId: $ownerContext->dataOwnerId,
            createdAt: $this->clock->now(),
            lastActivityAt: $this->clock->now(),
        );

        return SecurityContext::forSession($session);
    }

    /**
     * @param array<string, string> $query
     */
    private function getRequest(
        SecurityContext $context,
        array $query = [],
    ): Request {
        return Request::of('GET', AccessControlService::SUMMARY_PATH, query: $query)
            ->withAttribute(SessionResolverMiddleware::CONTEXT_ATTRIBUTE, $context);
    }

    private function createEntries(\Diary\Access\OwnerId $owner, array $dates): void
    {
        foreach ($dates as $date) {
            $input = DiaryEntryInput::of(date: LocalDate::fromString($date), moodRating: 6, sleepQuality: 3);
            $this->diaryService->submitEntry(
                $owner,
                DiaryValidation::accepted($input, $input->toSubmittedAnswers()),
                $this->clock,
            );
        }
    }

    public function testAnonymousRequestRedirectsToLogin(): void
    {
        $response = $this->controller->show(Request::of('GET', AccessControlService::SUMMARY_PATH));

        self::assertSame(302, $response->status());
        self::assertStringContainsString('/login', (string) $response->header('Location'));
    }

    public function testAViewerContextCanOpenTheSummaryPageReadOnly(): void
    {
        $ownerContext = $this->ownerContext();
        $viewerContext = $this->viewerContextFor($ownerContext);

        $response = $this->controller->show($this->getRequest($viewerContext));

        self::assertSame(200, $response->status());
    }

    public function testNoRangeSubmittedShowsOnlyThePickerWithNoDisclaimerAndNoProviderCall(): void
    {
        $context = $this->ownerContext();

        $response = $this->controller->show($this->getRequest($context));

        self::assertSame(200, $response->status());
        $html = $response->body();
        self::assertStringContainsString('<form method="get"', $html);
        self::assertStringNotContainsString('disclaimer', $html);
        self::assertSame(0, $this->provider->callCount());
    }

    public function testASubmittedRangeWithAWorkingProviderShowsNarrativeMetricsAndDisclaimer(): void
    {
        $context = $this->ownerContext();
        $owner = $this->access->resolveDataOwner($context);
        $this->createEntries($owner, ['2025-03-01', '2025-03-02', '2025-03-03']);

        $stats = SeriesStats::of(3, 6.0, 5, 7, TrendDirection::Stable);
        $this->provider->queue(new ProgressSummary(
            'Mood has been steady this month.',
            new CbtAdvice('pattern', 'distortions', 'balanced perspective', 'next action'),
            TrendMetrics::of(3, $stats, $stats)
        ));

        $response = $this->controller->show($this->getRequest($context, [
            SummaryController::START_PARAM => '2025-03-01',
            SummaryController::END_PARAM => '2025-03-31',
        ]));

        self::assertSame(200, $response->status());
        $html = $response->body();
        self::assertStringContainsString('Mood has been steady this month.', $html);
        self::assertStringContainsString('disclaimer', $html);
        self::assertStringContainsString(
            'This recommendation is automated guidance and is not a substitute for professional medical advice.',
            $html,
        );
    }

    public function testInsufficientDataShowsTheExactMessageAndDisclaimer(): void
    {
        $context = $this->ownerContext();
        $owner = $this->access->resolveDataOwner($context);
        $this->createEntries($owner, ['2025-03-01']);

        $response = $this->controller->show($this->getRequest($context, [
            SummaryController::START_PARAM => '2025-03-01',
            SummaryController::END_PARAM => '2025-03-31',
        ]));

        self::assertSame(200, $response->status());
        $html = $response->body();
        self::assertStringContainsString(\Diary\Ai\SummaryOutcome::INSUFFICIENT_DATA_MESSAGE, $html);
        self::assertStringContainsString('disclaimer', $html);
    }

    public function testProviderFailureShowsTheUnavailableMessageAndDisclaimer(): void
    {
        $context = $this->ownerContext();
        $owner = $this->access->resolveDataOwner($context);
        $this->createEntries($owner, ['2025-03-01', '2025-03-02', '2025-03-03']);

        $this->provider->queueFailure(new ProviderError('provider unreachable'));

        $response = $this->controller->show($this->getRequest($context, [
            SummaryController::START_PARAM => '2025-03-01',
            SummaryController::END_PARAM => '2025-03-31',
        ]));

        self::assertSame(200, $response->status());
        $html = $response->body();
        self::assertStringContainsString(\Diary\Ai\SummaryOutcome::UNAVAILABLE_MESSAGE, $html);
        self::assertStringContainsString('disclaimer', $html);
    }
}
