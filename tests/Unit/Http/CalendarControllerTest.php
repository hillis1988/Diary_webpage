<?php

declare(strict_types=1);

namespace Diary\Tests\Unit\Http;

use Diary\Access\AccessControlService;
use Diary\Ai\CbtRecommendation;
use Diary\Ai\CbtRecommendationRepository;
use Diary\Auth\SecurityContext;
use Diary\Auth\Session;
use Diary\Auth\SessionId;
use Diary\Auth\UserId;
use Diary\Auth\UserRole;
use Diary\Diary\CalendarService;
use Diary\Diary\DiaryEntryInput;
use Diary\Diary\DiaryEntryRepository;
use Diary\Diary\DiaryService;
use Diary\Http\CalendarController;
use Diary\Http\Request;
use Diary\Http\SessionResolverMiddleware;
use Diary\Milestone\MilestoneCategory;
use Diary\Milestone\MilestoneInput;
use Diary\Milestone\MilestoneRepository;
use Diary\Storage\ConnectionFactory;
use Diary\Storage\Crypto;
use Diary\Storage\KeyRing;
use Diary\Storage\PayloadCodec;
use Diary\Support\FixedClock;
use Diary\Support\LocalDate;
use Diary\Support\Ulid;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * The Calendar_View controller (Requirements 8.1, 8.2, 8.3, 8.4, 8.5).
 *
 * Uses an in-memory SQLite schema standing in for migrations 004, 005 and
 * 006, following the pattern in tests/Unit/Http/MilestoneControllerTest.php.
 */
final class CalendarControllerTest extends TestCase
{
    private PDO $pdo;
    private FixedClock $clock;
    private AccessControlService $access;
    private CalendarController $controller;
    private DiaryEntryRepository $diaryEntries;
    private MilestoneRepository $milestones;
    private CbtRecommendationRepository $cbtRecommendations;

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

        $this->pdo->exec(
            'CREATE TABLE cbt_recommendations (
                id                 CHAR(26)   NOT NULL PRIMARY KEY,
                entry_id           CHAR(26)   NOT NULL,
                status             VARCHAR(16) NOT NULL,
                key_id             CHAR(26)   NULL,
                nonce              BLOB       NULL,
                payload_ciphertext BLOB       NULL,
                provider           VARCHAR(64) NOT NULL,
                model              VARCHAR(64) NOT NULL,
                attempt_count      INTEGER    NOT NULL,
                generated_at       DATETIME   NULL,
                UNIQUE (entry_id)
            )'
        );

        $this->clock = FixedClock::at('2025-03-15 09:00:00');

        $keyRing = new KeyRing($this->pdo, str_repeat("\x2a", KeyRing::KEY_LENGTH), $this->clock);
        $codec = new PayloadCodec(new Crypto($keyRing));

        $this->diaryEntries = new DiaryEntryRepository($this->pdo, $codec);
        $this->milestones = new MilestoneRepository($this->pdo, $codec);
        $this->cbtRecommendations = new CbtRecommendationRepository($this->pdo, $codec);

        $this->access = new AccessControlService($this->clock);

        $this->controller = new CalendarController(
            $this->access,
            new CalendarService($this->diaryEntries, $this->milestones),
            new DiaryService($this->diaryEntries),
            $this->cbtRecommendations,
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
        string $path = AccessControlService::CALENDAR_PATH,
        array $query = [],
    ): Request {
        return Request::of('GET', $path, query: $query)
            ->withAttribute(SessionResolverMiddleware::CONTEXT_ATTRIBUTE, $context);
    }

    public function testAnonymousRequestRedirectsToLogin(): void
    {
        $response = $this->controller->show(Request::of('GET', AccessControlService::CALENDAR_PATH));

        self::assertSame(302, $response->status());
        self::assertStringContainsString('/login', (string) $response->header('Location'));
    }

    public function testAMonthWithNeitherEntriesNorMilestonesShowsNoIndicators(): void
    {
        $context = $this->ownerContext();

        $response = $this->controller->show($this->getRequest($context, query: ['month' => '2025-03']));

        self::assertSame(200, $response->status());
        $html = $response->body();
        self::assertStringNotContainsString('entry-indicator', $html);
        self::assertStringNotContainsString('milestone-indicator', $html);
    }

    public function testAMonthWithAnEntryShowsAnEntryIndicator(): void
    {
        $context = $this->ownerContext();
        $owner = $this->access->resolveDataOwner($context);

        $this->diaryEntries->upsert($owner, DiaryEntryInput::of(date: LocalDate::fromString('2025-03-05'), moodRating: 6), $this->clock);

        $response = $this->controller->show($this->getRequest($context, query: ['month' => '2025-03']));

        self::assertSame(200, $response->status());
        self::assertStringContainsString('entry-indicator', $response->body());
    }

    public function testAMonthWithAMilestoneShowsAMilestoneIndicator(): void
    {
        $context = $this->ownerContext();
        $owner = $this->access->resolveDataOwner($context);

        $this->milestones->create($owner, MilestoneInput::of(LocalDate::fromString('2025-03-08'), 'Something notable', MilestoneCategory::Other), $this->clock);

        $response = $this->controller->show($this->getRequest($context, query: ['month' => '2025-03']));

        self::assertSame(200, $response->status());
        self::assertStringContainsString('milestone-indicator', $response->body());
    }

    public function testSelectingADateWithAnEntryShowsTheEntryAndItsRecommendation(): void
    {
        $context = $this->ownerContext();
        $owner = $this->access->resolveDataOwner($context);

        $entry = $this->diaryEntries->upsert(
            $owner,
            DiaryEntryInput::of(date: LocalDate::fromString('2025-03-05'), moodRating: 6, events: 'A quiet day'),
            $this->clock,
        );

        $this->cbtRecommendations->recordSuccess(
            $entry->id(),
            new CbtRecommendation('Notice your calm moments', 'Take a short walk tomorrow'),
            'test-provider',
            'test-model',
            $this->clock->now(),
            $this->clock,
        );

        $response = $this->controller->show($this->getRequest($context, query: ['month' => '2025-03', 'date' => '2025-03-05']));

        self::assertSame(200, $response->status());
        $html = $response->body();
        self::assertStringContainsString('A quiet day', $html);
        self::assertStringContainsString('Notice your calm moments', $html);
        self::assertStringContainsString('Take a short walk tomorrow', $html);
        self::assertStringNotContainsString(CalendarController::NO_ENTRY_MESSAGE, $html);
    }

    public function testSelectingADateWithNoEntryShowsTheNoEntryMessage(): void
    {
        $context = $this->ownerContext();

        $response = $this->controller->show($this->getRequest($context, query: ['month' => '2025-03', 'date' => '2025-03-09']));

        self::assertSame(200, $response->status());
        self::assertStringContainsString(CalendarController::NO_ENTRY_MESSAGE, $response->body());
    }

    public function testIndicatorsAndSelectedEntriesAreScopedToTheResolvedOwnerOnly(): void
    {
        $ownerContext = $this->ownerContext();
        $owner = $this->access->resolveDataOwner($ownerContext);

        $otherOwnerContext = $this->ownerContext();
        $otherOwner = $this->access->resolveDataOwner($otherOwnerContext);

        $this->diaryEntries->upsert($otherOwner, DiaryEntryInput::of(date: LocalDate::fromString('2025-03-05'), moodRating: 6, events: 'Belongs to someone else'), $this->clock);
        $this->milestones->create($otherOwner, MilestoneInput::of(LocalDate::fromString('2025-03-05'), 'Not this owner', MilestoneCategory::Other), $this->clock);

        $response = $this->controller->show($this->getRequest($ownerContext, query: ['month' => '2025-03', 'date' => '2025-03-05']));

        self::assertSame(200, $response->status());
        $html = $response->body();
        self::assertStringNotContainsString('entry-indicator', $html);
        self::assertStringNotContainsString('milestone-indicator', $html);
        self::assertStringContainsString(CalendarController::NO_ENTRY_MESSAGE, $html);
        self::assertStringNotContainsString('Belongs to someone else', $html);
    }

    public function testAViewerContextCanOpenTheCalendarReadOnly(): void
    {
        $ownerContext = $this->ownerContext();
        $owner = $this->access->resolveDataOwner($ownerContext);
        $this->diaryEntries->upsert($owner, DiaryEntryInput::of(date: LocalDate::fromString('2025-03-05'), moodRating: 6), $this->clock);

        $viewerContext = $this->viewerContextFor($ownerContext);

        $response = $this->controller->show($this->getRequest($viewerContext, query: ['month' => '2025-03']));

        self::assertSame(200, $response->status());
        self::assertStringContainsString('entry-indicator', $response->body());
    }

    public function testAnUnparsableMonthFallsBackToTheCurrentMonth(): void
    {
        $context = $this->ownerContext();
        $owner = $this->access->resolveDataOwner($context);
        $this->diaryEntries->upsert($owner, DiaryEntryInput::of(date: LocalDate::fromString('2025-03-05'), moodRating: 6), $this->clock);

        $response = $this->controller->show($this->getRequest($context, query: ['month' => 'not-a-month']));

        self::assertSame(200, $response->status());
        self::assertStringContainsString('entry-indicator', $response->body());
    }
}
