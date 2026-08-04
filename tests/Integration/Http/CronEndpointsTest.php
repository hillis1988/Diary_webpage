<?php

declare(strict_types=1);

namespace Diary\Tests\Integration\Http;

use Diary\Access\OwnerId;
use Diary\Auth\EmailAddress;
use Diary\Auth\UserAccount;
use Diary\Auth\UserId;
use Diary\Auth\UserRepository;
use Diary\Diary\DiaryEntryInput;
use Diary\Diary\DiaryEntryRepository;
use Diary\Http\CronAuth;
use Diary\Http\CronController;
use Diary\Http\CsrfGuard;
use Diary\Http\CsrfMiddleware;
use Diary\Http\HttpsRedirectMiddleware;
use Diary\Http\Pipeline;
use Diary\Http\Request;
use Diary\Http\Router;
use Diary\Http\SecurityHeadersMiddleware;
use Diary\Milestone\MilestoneCategory;
use Diary\Milestone\MilestoneInput;
use Diary\Milestone\MilestoneRepository;
use Diary\Storage\Crypto;
use Diary\Storage\KeyRing;
use Diary\Storage\KeyRotationService;
use Diary\Storage\PayloadCodec;
use Diary\Storage\PurgeJobRepository;
use Diary\Storage\PurgeService;
use Diary\Support\FixedClock;
use Diary\Support\LocalDate;
use Diary\Tests\Integration\MariaDbTestSchema;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * The three `/cron/*` endpoints dispatched through the real cron pipeline
 * (Requirements 4.2, 4.5), against a throwaway MariaDB schema.
 *
 * {@see \Diary\Tests\Unit\Http\CronControllerTest} already proves token
 * rejection, a successful slice, and same-process idempotence by calling
 * the controller's methods directly against an in-memory SQLite schema. That
 * leaves a gap only a real server can close: whether the SQL each service
 * issues (`SELECT ... FOR UPDATE`, the MySQL-flavoured upsert, the
 * information_schema-free retire/re-encrypt queries) actually runs correctly
 * on MariaDB, and whether a request dispatched through the real
 * `$cronPipeline` shape from `public/index.php` - HTTPS redirect, security
 * headers, CSRF, no session resolution, no authorisation - reaches the
 * controller the same way. This test exercises exactly that: real services,
 * real router, real pipeline, real MariaDB rows.
 *
 * With no MariaDB server reachable the whole class skips with a message
 * explaining how to run it (see {@see MariaDbTestSchema}).
 */
final class CronEndpointsTest extends TestCase
{
    private const TOKEN = 'integration-cron-secret-token';

    /** Mirrors CronController::PURGE_LIMIT; there is no public constant to read. */
    private const PURGE_LIMIT = 50;

    private MariaDbTestSchema $schema;
    private PDO $pdo;
    private FixedClock $clock;
    private KeyRing $keyRing;
    private PayloadCodec $codec;
    private UserRepository $users;
    private DiaryEntryRepository $entries;
    private MilestoneRepository $milestones;
    private PurgeJobRepository $purgeJobs;
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

        (new \Diary\Storage\MigrationRunner($this->pdo, new FixedClock(new \DateTimeImmutable('2025-01-01 00:00:00'))))
            ->migrateDirectory(dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'migrations');

        $this->clock = FixedClock::at('2025-03-01 09:30:00');
        $this->keyRing = new KeyRing($this->pdo, str_repeat("\x2a", KeyRing::KEY_LENGTH), $this->clock);
        $this->codec = new PayloadCodec(new Crypto($this->keyRing));
        $this->users = new UserRepository($this->pdo);
        $this->entries = new DiaryEntryRepository($this->pdo, $this->codec);
        $this->milestones = new MilestoneRepository($this->pdo, $this->codec);
        $this->purgeJobs = new PurgeJobRepository($this->pdo);

        $controller = new CronController(
            new CronAuth(self::TOKEN),
            new PurgeService($this->pdo, $this->purgeJobs, $this->clock),
            new \Diary\Auth\SessionRepository($this->pdo),
            new KeyRotationService($this->pdo, $this->keyRing, $this->codec),
            $this->clock,
        );

        $this->pipeline = $this->buildCronPipeline($controller);
    }

    protected function tearDown(): void
    {
        if (isset($this->schema)) {
            $this->schema->drop();
        }
    }

    // -- token rejection, no work done -----------------------------------

    public function testMissingOrWrongTokenIsRejectedByEveryEndpointAndPerformsNoWork(): void
    {
        [$owner, $ownerId] = $this->createOwner('reject');
        $this->entries->upsert($ownerId, $this->sampleEntryInput('2025-02-01'), $this->clock);
        $this->purgeJobs->create($owner->id, $this->clock);
        $this->insertRawSession('s-reject-expired', $owner->id, '2025-01-01 00:00:00', '2025-01-01 00:00:00');

        $beforeUsers = $this->countRows('users');
        $beforeOutstandingJobs = $this->countOutstandingPurgeJobs();
        $beforeSessions = $this->countRows('sessions');
        $beforeKeyId = $this->diaryEntryKeyId($ownerId, '2025-02-01');

        $missing = $this->dispatch('/cron/purge', null);
        $wrongPurge = $this->dispatch('/cron/purge', 'wrong-token');
        $wrongSessions = $this->dispatch('/cron/sessions', 'wrong-token');
        $wrongKeys = $this->dispatch('/cron/keys', 'wrong-token');

        self::assertSame(401, $missing->status());
        self::assertSame(401, $wrongPurge->status());
        self::assertSame(401, $wrongSessions->status());
        self::assertSame(401, $wrongKeys->status());

        self::assertSame($beforeUsers, $this->countRows('users'), 'a rejected purge call must delete no user');
        self::assertSame(
            $beforeOutstandingJobs,
            $this->countOutstandingPurgeJobs(),
            'a rejected purge call must not touch the outstanding purge_jobs row'
        );
        self::assertSame($beforeSessions, $this->countRows('sessions'), 'a rejected sessions call must delete no session');
        self::assertSame(
            $beforeKeyId,
            $this->diaryEntryKeyId($ownerId, '2025-02-01'),
            'a rejected keys call must re-encrypt nothing'
        );
    }

    // -- successful runs against real MariaDB rows -----------------------

    public function testPurgeWithAValidTokenPurgesEveryTableForTheOwnerAgainstRealMariaDb(): void
    {
        [$owner, $ownerId] = $this->createOwner('purge');
        $entry = $this->entries->upsert($ownerId, $this->sampleEntryInput('2025-02-01'), $this->clock);
        $this->milestones->create($ownerId, MilestoneInput::of(
            LocalDate::fromString('2025-02-02'),
            'Started a new medication',
            MilestoneCategory::Medication,
        ), $this->clock);
        $this->insertRawCbtRecommendation($entry->id(), $owner->id);
        $this->purgeJobs->create($owner->id, $this->clock);

        $response = $this->dispatch('/cron/purge', self::TOKEN);

        self::assertSame(200, $response->status());
        self::assertStringContainsString('succeeded=1', $response->body());
        self::assertSame(0, $this->countRows('users'), 'the owner row must be gone');
        self::assertSame(0, $this->countRows('diary_entries'), 'the entry must be purged');
        self::assertSame(0, $this->countRows('milestones'), 'the milestone must be purged');
        self::assertSame(0, $this->countRows('cbt_recommendations'), 'the recommendation must be purged');
        self::assertSame(0, $this->countOutstandingPurgeJobs(), 'the job must be marked completed');
    }

    public function testSessionsWithAValidTokenDeletesRealExpiredSessionRows(): void
    {
        [$owner] = $this->createOwner('sessions');
        $this->insertRawSession('s-sessions-expired', $owner->id, '2025-01-01 00:00:00', '2025-01-01 00:00:00');
        $this->insertRawSession('s-sessions-live', $owner->id, null, $this->clock->now()->format('Y-m-d H:i:s'));

        $response = $this->dispatch('/cron/sessions', self::TOKEN);

        self::assertSame(200, $response->status());
        self::assertStringContainsString('deleted=1', $response->body());
        self::assertSame(1, $this->countRows('sessions'), 'only the expired session should have been deleted');
        self::assertSame(0, $this->countRowsWhere('sessions', 'id = :id', [':id' => 's-sessions-expired']));
        self::assertSame(1, $this->countRowsWhere('sessions', 'id = :id', [':id' => 's-sessions-live']));
    }

    public function testKeysWithAValidTokenReEncryptsARealRowAndRetiresTheUnreferencedOldKey(): void
    {
        [, $ownerId] = $this->createOwner('keys');
        $entry = $this->entries->upsert($ownerId, $this->sampleEntryInput('2025-02-01'), $this->clock);
        $oldKeyId = $this->diaryEntryKeyId($ownerId, '2025-02-01');

        // Rotate: a newer key becomes active; the row above still references
        // the old one until the cron slice moves it.
        $this->clock->advanceDays(1);
        $newKeyId = $this->keyRing->createKey();
        $this->keyRing->forget();
        self::assertNotSame($oldKeyId, $newKeyId);

        $response = $this->dispatch('/cron/keys', self::TOKEN);

        self::assertSame(200, $response->status());
        self::assertStringContainsString('re_encrypted=1', $response->body());
        self::assertStringContainsString('retired_keys=1', $response->body());
        self::assertSame($newKeyId, $this->diaryEntryKeyId($ownerId, '2025-02-01'), 'the row must now reference the active key');
        self::assertSame(1, $this->countRowsWhere('encryption_keys', 'id = :id AND retired_at IS NOT NULL', [':id' => $oldKeyId]));
        self::assertSame(0, $this->countRowsWhere('encryption_keys', 'id = :id AND retired_at IS NOT NULL', [':id' => $newKeyId]));

        // The entry must still decrypt correctly under its new key.
        $reread = $this->entries->findByDate($ownerId, LocalDate::fromString('2025-02-01'));
        self::assertNotNull($reread);
        self::assertSame($entry->input()->moodRating(), $reread->input()->moodRating());
    }

    // -- idempotence -------------------------------------------------------

    public function testRunningEachEndpointTwiceInARowAgainstTheSameRealDatabaseStateIsSafe(): void
    {
        [$purgeOwner] = $this->createOwner('idem-purge');
        $this->purgeJobs->create($purgeOwner->id, $this->clock);

        $firstPurge = $this->dispatch('/cron/purge', self::TOKEN);
        $secondPurge = $this->dispatch('/cron/purge', self::TOKEN);
        self::assertSame(200, $firstPurge->status());
        self::assertSame(200, $secondPurge->status());
        self::assertStringContainsString('succeeded=1', $firstPurge->body());
        self::assertStringContainsString('attempted=0', $secondPurge->body(), 'no outstanding job is left to retry');

        [$sessionOwner] = $this->createOwner('idem-sessions');
        $this->insertRawSession('s-idem-expired', $sessionOwner->id, '2025-01-01 00:00:00', '2025-01-01 00:00:00');

        $firstSessions = $this->dispatch('/cron/sessions', self::TOKEN);
        $secondSessions = $this->dispatch('/cron/sessions', self::TOKEN);
        self::assertSame(200, $firstSessions->status());
        self::assertSame(200, $secondSessions->status());
        self::assertStringContainsString('deleted=1', $firstSessions->body());
        self::assertStringContainsString('deleted=0', $secondSessions->body(), 'the row is already gone the second time');

        [, $keysOwnerId] = $this->createOwner('idem-keys');
        $this->entries->upsert($keysOwnerId, $this->sampleEntryInput('2025-02-01'), $this->clock);
        $this->clock->advanceDays(1);
        $this->keyRing->createKey();
        $this->keyRing->forget();

        $firstKeys = $this->dispatch('/cron/keys', self::TOKEN);
        $secondKeys = $this->dispatch('/cron/keys', self::TOKEN);
        self::assertSame(200, $firstKeys->status());
        self::assertSame(200, $secondKeys->status());
        self::assertStringContainsString('re_encrypted=1', $firstKeys->body());
        self::assertStringContainsString('re_encrypted=0', $secondKeys->body(), 'nothing is left on an old key the second time');
        self::assertStringNotContainsString('failed=1', $secondKeys->body());
    }

    // -- bounded work --------------------------------------------------

    /**
     * CronControllerTest already proves the *mechanism* of a bounded slice
     * against SQLite; this proves the same bound holds for real work against
     * real MariaDB rows without paying the cost of doing it at the full
     * SESSIONS_LIMIT/KEYS_LIMIT scale (500 / 200), which would be wasteful
     * here and adds no further confidence beyond what the unit test already
     * shows about those two limits.
     */
    public function testPurgeOnlyProcessesUpToItsConfiguredLimitPerRealRun(): void
    {
        $total = self::PURGE_LIMIT + 2;

        for ($i = 0; $i < $total; $i++) {
            [$owner] = $this->createOwner(sprintf('bound-%03d', $i));
            $this->purgeJobs->create($owner->id, $this->clock);
        }

        self::assertSame($total, $this->countOutstandingPurgeJobs());

        $first = $this->dispatch('/cron/purge', self::TOKEN);

        self::assertSame(200, $first->status());
        self::assertStringContainsString('attempted=' . self::PURGE_LIMIT, $first->body());
        self::assertSame(
            $total - self::PURGE_LIMIT,
            $this->countOutstandingPurgeJobs(),
            'exactly the configured limit of jobs should have been retried in one run'
        );

        $second = $this->dispatch('/cron/purge', self::TOKEN);

        self::assertSame(200, $second->status());
        self::assertStringContainsString('attempted=' . ($total - self::PURGE_LIMIT), $second->body());
        self::assertSame(0, $this->countOutstandingPurgeJobs(), 'the remainder is cleared on the next run');
    }

    // -- pipeline construction, matching public/index.php's $cronPipeline --

    private function buildCronPipeline(CronController $controller): Pipeline
    {
        $router = new Router();
        $router->get('/cron/purge', static fn (Request $r, array $p) => $controller->purge($r));
        $router->get('/cron/sessions', static fn (Request $r, array $p) => $controller->sessions($r));
        $router->get('/cron/keys', static fn (Request $r, array $p) => $controller->keys($r));

        // Same shape as public/index.php's $cronPipeline: HTTPS redirect and
        // security headers, CSRF, but no session resolution and no
        // authorisation - a cron call carries no session cookie at all.
        return Pipeline::fixedOrder(
            new HttpsRedirectMiddleware('https://royhillis.co.uk'),
            new SecurityHeadersMiddleware(),
            new CsrfMiddleware(new CsrfGuard(str_repeat('k', 32))),
            null,
            null,
            $router,
        );
    }

    private function dispatch(string $path, ?string $token): \Diary\Http\Response
    {
        $request = $token === null
            ? Request::of('GET', $path, true)
            : Request::of('GET', $path, true, query: ['token' => $token]);

        return $this->pipeline->handle($request);
    }

    // -- fixtures -----------------------------------------------------

    /**
     * @return array{0: UserAccount, 1: OwnerId}
     */
    private function createOwner(string $suffix): array
    {
        $email = EmailAddress::fromInput($suffix . '-' . bin2hex(random_bytes(4)) . '@example.com');
        $account = UserAccount::newOwner(
            UserId::fromString(\Diary\Support\Ulid::generate($this->clock)),
            $email,
            'not-a-real-hash',
            $this->clock->now(),
        );
        $this->users->insert($account);

        return [$account, OwnerId::fromUserId($account->id)];
    }

    private function sampleEntryInput(string $isoDate): DiaryEntryInput
    {
        return DiaryEntryInput::of(
            date: LocalDate::fromString($isoDate),
            moodRating: 6,
            sleepQuality: 3,
            events: 'A quiet day',
            thoughts: 'Feeling steady',
            emotions: 'calm',
        );
    }

    private function insertRawSession(string $id, UserId $userId, ?string $terminatedAt, string $lastActivityAt): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO sessions (id, user_id, context_role, data_owner_id, created_at, last_activity_at, terminated_at)
             VALUES (:id, :user_id, :context_role, :data_owner_id, :created_at, :last_activity_at, :terminated_at)'
        );
        $statement->execute([
            ':id' => $id,
            ':user_id' => $userId->toString(),
            ':context_role' => 'owner',
            ':data_owner_id' => $userId->toString(),
            ':created_at' => '2025-01-01 00:00:00',
            ':last_activity_at' => $lastActivityAt,
            ':terminated_at' => $terminatedAt,
        ]);
    }

    private function insertRawCbtRecommendation(string $entryId, UserId $ownerId): void
    {
        $repository = new \Diary\Ai\CbtRecommendationRepository($this->pdo, $this->codec);
        $repository->recordSuccess(
            $entryId,
            new \Diary\Ai\CbtRecommendation('Keep noticing what steadies you', 'Take a five-minute walk tomorrow'),
            'example-provider',
            'example-model',
            $this->clock->now(),
            $this->clock,
        );
        // $ownerId is not needed by the repository, kept only for call-site clarity.
        unset($ownerId);
    }

    private function countRows(string $table): int
    {
        $result = $this->pdo->query('SELECT COUNT(*) FROM ' . $table);
        self::assertNotFalse($result);

        return (int) $result->fetchColumn();
    }

    /**
     * @param array<string, mixed> $params
     */
    private function countRowsWhere(string $table, string $where, array $params): int
    {
        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM ' . $table . ' WHERE ' . $where);
        $statement->execute($params);

        return (int) $statement->fetchColumn();
    }

    private function countOutstandingPurgeJobs(): int
    {
        return $this->countRowsWhere('purge_jobs', 'completed_at IS NULL', []);
    }

    private function diaryEntryKeyId(OwnerId $owner, string $isoDate): string
    {
        $statement = $this->pdo->prepare(
            'SELECT key_id FROM diary_entries WHERE owner_id = :owner_id AND entry_date = :entry_date'
        );
        $statement->execute([':owner_id' => $owner->toString(), ':entry_date' => $isoDate]);
        $keyId = $statement->fetchColumn();
        self::assertIsString($keyId);

        return $keyId;
    }
}
