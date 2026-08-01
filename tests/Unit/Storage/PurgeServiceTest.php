<?php

declare(strict_types=1);

namespace Diary\Tests\Unit\Storage;

use Diary\Auth\EmailAddress;
use Diary\Auth\UserAccount;
use Diary\Auth\UserId;
use Diary\Storage\ConnectionFactory;
use Diary\Storage\PurgeJobRepository;
use Diary\Storage\PurgeService;
use Diary\Support\FixedClock;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * PurgeService: immediate account deletion and the cron retry slice
 * (Requirement 4.5).
 *
 * Uses an in-memory SQLite schema standing in for migrations 001, 002, 004,
 * 005, 006, 007 and 008, following the pattern in
 * tests/Unit/Diary/DiaryEntryRepositoryTest.php and
 * tests/Unit/Auth/SqliteAuthTables.php. Ciphertext columns are populated with
 * arbitrary bytes rather than real envelope encryption, because PurgeService
 * never reads a payload - it only deletes rows - so the encryption layer
 * itself is out of scope for this test.
 */
final class PurgeServiceTest extends TestCase
{
    private PDO $pdo;
    private PurgeJobRepository $jobRepository;
    private PurgeService $service;
    private FixedClock $clock;

    protected function setUp(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_sqlite is not loaded.');
        }

        $this->pdo = ConnectionFactory::fromDsn('sqlite::memory:');

        $this->createSchema();

        $this->clock = FixedClock::at('2025-03-01 09:30:00');
        $this->jobRepository = new PurgeJobRepository($this->pdo);
        $this->service = new PurgeService($this->pdo, $this->jobRepository, $this->clock);
    }

    private function createSchema(): void
    {
        $this->pdo->exec(
            'CREATE TABLE users (
                id                     TEXT    NOT NULL PRIMARY KEY,
                email_normalized       TEXT    NOT NULL,
                email_display          TEXT    NOT NULL,
                password_hash          TEXT        NULL,
                role                   TEXT    NOT NULL CHECK (role IN (\'owner\', \'viewer\')),
                data_owner_id          TEXT    NOT NULL,
                status                 TEXT    NOT NULL DEFAULT \'invited\',
                failed_login_count     INTEGER NOT NULL DEFAULT 0,
                locked_until           TEXT        NULL,
                deletion_requested_at  TEXT        NULL,
                created_at             TEXT    NOT NULL,
                updated_at             TEXT    NOT NULL
            )'
        );

        $this->pdo->exec(
            'CREATE TABLE sessions (
                id               TEXT NOT NULL PRIMARY KEY,
                user_id          TEXT NOT NULL,
                context_role     TEXT NOT NULL,
                data_owner_id    TEXT NOT NULL,
                created_at       TEXT NOT NULL,
                last_activity_at TEXT NOT NULL,
                terminated_at    TEXT     NULL
            )'
        );

        $this->pdo->exec(
            'CREATE TABLE diary_entries (
                id                 TEXT NOT NULL PRIMARY KEY,
                owner_id           TEXT NOT NULL,
                entry_date         TEXT NOT NULL,
                key_id             TEXT NOT NULL,
                nonce              BLOB NOT NULL,
                payload_ciphertext BLOB NOT NULL,
                created_at         TEXT NOT NULL,
                updated_at         TEXT NOT NULL
            )'
        );

        $this->pdo->exec(
            'CREATE TABLE cbt_recommendations (
                id                 TEXT NOT NULL PRIMARY KEY,
                entry_id           TEXT NOT NULL,
                status             TEXT NOT NULL,
                key_id             TEXT     NULL,
                nonce              BLOB     NULL,
                payload_ciphertext BLOB     NULL,
                provider           TEXT     NULL,
                model              TEXT     NULL,
                attempt_count      INTEGER  NOT NULL DEFAULT 0,
                generated_at       TEXT     NULL
            )'
        );

        $this->pdo->exec(
            'CREATE TABLE milestones (
                id                 TEXT NOT NULL PRIMARY KEY,
                owner_id           TEXT NOT NULL,
                milestone_date     TEXT NOT NULL,
                key_id             TEXT NOT NULL,
                nonce              BLOB NOT NULL,
                payload_ciphertext BLOB NOT NULL,
                created_at         TEXT NOT NULL,
                updated_at         TEXT NOT NULL
            )'
        );

        $this->pdo->exec(
            'CREATE TABLE purge_jobs (
                id            TEXT    NOT NULL PRIMARY KEY,
                user_id       TEXT    NOT NULL,
                requested_at  TEXT    NOT NULL,
                completed_at  TEXT        NULL,
                attempt_count INTEGER NOT NULL DEFAULT 0,
                last_error    TEXT        NULL
            )'
        );

        $this->pdo->exec(
            'CREATE TABLE audit_log (
                id            TEXT NOT NULL PRIMARY KEY,
                actor_user_id TEXT     NULL,
                context_role  TEXT NOT NULL DEFAULT \'anonymous\',
                action        TEXT NOT NULL,
                target_type   TEXT     NULL,
                target_id     TEXT     NULL,
                outcome       TEXT NOT NULL,
                ip_hash       TEXT     NULL,
                occurred_at   TEXT NOT NULL
            )'
        );
    }

    private function ulid(string $suffix): string
    {
        return '0' . str_repeat('1', 25 - strlen($suffix)) . $suffix;
    }

    private function insertOwner(string $id, string $email = 'owner@example.com'): UserId
    {
        $userId = UserId::fromString($id);
        $now = $this->clock->now();

        $account = UserAccount::newOwner($userId, EmailAddress::fromInput($email), 'hash', $now);

        $statement = $this->pdo->prepare(
            'INSERT INTO users (id, email_normalized, email_display, password_hash, role, data_owner_id,
                status, failed_login_count, locked_until, deletion_requested_at, created_at, updated_at)
             VALUES (:id, :email_normalized, :email_display, :password_hash, :role, :data_owner_id,
                :status, 0, NULL, NULL, :created_at, :updated_at)'
        );
        $statement->execute([
            ':id' => $account->id->toString(),
            ':email_normalized' => $account->emailNormalized,
            ':email_display' => $account->emailDisplay,
            ':password_hash' => $account->passwordHash,
            ':role' => $account->role->value,
            ':data_owner_id' => $account->dataOwnerId->toString(),
            ':status' => $account->status->value,
            ':created_at' => $now->format('Y-m-d H:i:s'),
            ':updated_at' => $now->format('Y-m-d H:i:s'),
        ]);

        return $userId;
    }

    private function insertViewer(string $id, string $ownerId, string $email): UserId
    {
        $viewerId = UserId::fromString($id);
        $now = $this->clock->now();

        $statement = $this->pdo->prepare(
            'INSERT INTO users (id, email_normalized, email_display, password_hash, role, data_owner_id,
                status, failed_login_count, locked_until, deletion_requested_at, created_at, updated_at)
             VALUES (:id, :email, :email, \'hash\', \'viewer\', :owner_id, \'active\', 0, NULL, NULL, :now, :now)'
        );
        $statement->execute([
            ':id' => $viewerId->toString(),
            ':email' => $email,
            ':owner_id' => $ownerId,
            ':now' => $now->format('Y-m-d H:i:s'),
        ]);

        return $viewerId;
    }

    private function insertSession(string $id, string $userId, string $dataOwnerId, string $role = 'owner'): void
    {
        $now = $this->clock->now()->format('Y-m-d H:i:s');

        $statement = $this->pdo->prepare(
            'INSERT INTO sessions (id, user_id, context_role, data_owner_id, created_at, last_activity_at, terminated_at)
             VALUES (:id, :user_id, :role, :owner_id, :now, :now, NULL)'
        );
        $statement->execute([
            ':id' => $id,
            ':user_id' => $userId,
            ':role' => $role,
            ':owner_id' => $dataOwnerId,
            ':now' => $now,
        ]);
    }

    private function insertEntry(string $id, string $ownerId, string $date = '2025-03-01'): void
    {
        $now = $this->clock->now()->format('Y-m-d H:i:s');

        $statement = $this->pdo->prepare(
            'INSERT INTO diary_entries (id, owner_id, entry_date, key_id, nonce, payload_ciphertext, created_at, updated_at)
             VALUES (:id, :owner_id, :date, :key_id, :nonce, :ct, :now, :now)'
        );
        $statement->bindValue(':id', $id);
        $statement->bindValue(':owner_id', $ownerId);
        $statement->bindValue(':date', $date);
        $statement->bindValue(':key_id', $this->ulid('9'));
        $statement->bindValue(':nonce', random_bytes(12), PDO::PARAM_LOB);
        $statement->bindValue(':ct', random_bytes(32), PDO::PARAM_LOB);
        $statement->bindValue(':now', $now);
        $statement->execute();
    }

    private function insertRecommendation(string $id, string $entryId): void
    {
        $now = $this->clock->now()->format('Y-m-d H:i:s');

        $statement = $this->pdo->prepare(
            'INSERT INTO cbt_recommendations (id, entry_id, status, key_id, nonce, payload_ciphertext, provider, model, attempt_count, generated_at)
             VALUES (:id, :entry_id, \'generated\', :key_id, :nonce, :ct, \'test\', \'test\', 1, :now)'
        );
        $statement->bindValue(':id', $id);
        $statement->bindValue(':entry_id', $entryId);
        $statement->bindValue(':key_id', $this->ulid('9'));
        $statement->bindValue(':nonce', random_bytes(12), PDO::PARAM_LOB);
        $statement->bindValue(':ct', random_bytes(32), PDO::PARAM_LOB);
        $statement->bindValue(':now', $now);
        $statement->execute();
    }

    private function insertMilestone(string $id, string $ownerId): void
    {
        $now = $this->clock->now()->format('Y-m-d H:i:s');

        $statement = $this->pdo->prepare(
            'INSERT INTO milestones (id, owner_id, milestone_date, key_id, nonce, payload_ciphertext, created_at, updated_at)
             VALUES (:id, :owner_id, :date, :key_id, :nonce, :ct, :now, :now)'
        );
        $statement->bindValue(':id', $id);
        $statement->bindValue(':owner_id', $ownerId);
        $statement->bindValue(':date', '2025-03-01');
        $statement->bindValue(':key_id', $this->ulid('9'));
        $statement->bindValue(':nonce', random_bytes(12), PDO::PARAM_LOB);
        $statement->bindValue(':ct', random_bytes(32), PDO::PARAM_LOB);
        $statement->bindValue(':now', $now);
        $statement->execute();
    }

    private function insertAuditRow(string $id, ?string $actorUserId, ?string $targetId): void
    {
        $now = $this->clock->now()->format('Y-m-d H:i:s');

        $statement = $this->pdo->prepare(
            'INSERT INTO audit_log (id, actor_user_id, context_role, action, target_type, target_id, outcome, ip_hash, occurred_at)
             VALUES (:id, :actor, \'owner\', \'sign_in\', \'session\', :target, \'success\', NULL, :now)'
        );
        $statement->execute([
            ':id' => $id,
            ':actor' => $actorUserId,
            ':target' => $targetId,
            ':now' => $now,
        ]);
    }

    private function countRows(string $table, string $where = '1=1'): int
    {
        $result = $this->pdo->query("SELECT COUNT(*) AS c FROM $table WHERE $where")->fetch(PDO::FETCH_ASSOC);

        return (int) $result['c'];
    }

    public function testRequestDeletionPurgesEveryOwnedRowAndLinkedViewerAccountsAndSessions(): void
    {
        $ownerId = $this->insertOwner($this->ulid('1'));
        $viewerId = $this->insertViewer($this->ulid('2'), $ownerId->toString(), 'viewer@example.com');

        $this->insertSession($this->ulid('3'), $ownerId->toString(), $ownerId->toString());
        $this->insertSession($this->ulid('4'), $viewerId->toString(), $ownerId->toString(), 'viewer');

        $this->insertEntry($this->ulid('5'), $ownerId->toString());
        $this->insertRecommendation($this->ulid('6'), $this->ulid('5'));
        $this->insertMilestone($this->ulid('7'), $ownerId->toString());

        $result = $this->service->requestDeletion($ownerId, $this->clock);

        self::assertTrue($result->isOk());

        self::assertSame(0, $this->countRows('users'));
        self::assertSame(0, $this->countRows('sessions'));
        self::assertSame(0, $this->countRows('diary_entries'));
        self::assertSame(0, $this->countRows('cbt_recommendations'));
        self::assertSame(0, $this->countRows('milestones'));

        // The purge job was recorded and, on immediate success, marked completed.
        $jobRow = $this->pdo->query('SELECT completed_at FROM purge_jobs')->fetch(PDO::FETCH_ASSOC);
        self::assertNotNull($jobRow);
        self::assertNotNull($jobRow['completed_at']);
    }

    public function testRequestDeletionDoesNotTouchAnotherOwnersData(): void
    {
        $ownerId = $this->insertOwner($this->ulid('1'), 'owner1@example.com');
        $otherOwnerId = $this->insertOwner($this->ulid('2'), 'owner2@example.com');

        $this->insertEntry($this->ulid('3'), $otherOwnerId->toString());
        $this->insertMilestone($this->ulid('4'), $otherOwnerId->toString());

        $this->service->requestDeletion($ownerId, $this->clock);

        self::assertSame(1, $this->countRows('users'));
        self::assertSame(1, $this->countRows('diary_entries'));
        self::assertSame(1, $this->countRows('milestones'));
        self::assertNotNull($this->findUser($otherOwnerId->toString()));
    }

    public function testRequestDeletionStripsAuditRowsReferencingDeletedContentButLeavesOtherRowsIntact(): void
    {
        $ownerId = $this->insertOwner($this->ulid('1'));
        $otherOwnerId = $this->insertOwner($this->ulid('2'), 'other@example.com');

        // References the owner being deleted: both columns must be nulled.
        $this->insertAuditRow($this->ulid('3'), $ownerId->toString(), $ownerId->toString());
        // Belongs to a different, unrelated account: must be left completely intact.
        $this->insertAuditRow($this->ulid('4'), $otherOwnerId->toString(), $otherOwnerId->toString());

        $this->service->requestDeletion($ownerId, $this->clock);

        $strippedRow = $this->pdo->prepare('SELECT actor_user_id, target_id FROM audit_log WHERE id = :id');
        $strippedRow->execute([':id' => $this->ulid('3')]);
        $stripped = $strippedRow->fetch(PDO::FETCH_ASSOC);
        self::assertNotFalse($stripped, 'the audit row itself must survive the purge');
        self::assertNull($stripped['actor_user_id']);
        self::assertNull($stripped['target_id']);

        $untouchedRow = $this->pdo->prepare('SELECT actor_user_id, target_id FROM audit_log WHERE id = :id');
        $untouchedRow->execute([':id' => $this->ulid('4')]);
        $untouched = $untouchedRow->fetch(PDO::FETCH_ASSOC);
        self::assertSame($otherOwnerId->toString(), $untouched['actor_user_id']);
        self::assertSame($otherOwnerId->toString(), $untouched['target_id']);

        self::assertSame(2, $this->countRows('audit_log'));
    }

    public function testAFailedPurgeLeavesAnOutstandingPurgeJobRatherThanACompletedOne(): void
    {
        $ownerId = $this->insertOwner($this->ulid('1'));

        // Force the transactional purge to fail partway: drop a table the
        // service's SQL depends on, so its DELETE statement throws and the
        // transaction rolls back, exactly like a real storage fault would.
        $this->pdo->exec('DROP TABLE milestones');

        $result = $this->service->requestDeletion($ownerId, $this->clock);

        // requestDeletion itself still reports success: the request was
        // recorded, only the immediate purge attempt failed.
        self::assertTrue($result->isOk());

        // The user row was rolled back to existing (the transaction failed),
        // so the deletion_requested_at write from outside the transaction is
        // the only lasting effect, plus the purge_jobs bookkeeping.
        $jobRow = $this->pdo->query('SELECT completed_at, attempt_count, last_error FROM purge_jobs')
            ->fetch(PDO::FETCH_ASSOC);
        self::assertNotNull($jobRow);
        self::assertNull($jobRow['completed_at']);
        self::assertSame(1, (int) $jobRow['attempt_count']);
        self::assertNotNull($jobRow['last_error']);

        // The user row survives because the transaction rolled back.
        self::assertNotNull($this->findUser($ownerId->toString()));
    }

    public function testRunPurgeSliceRetriesAnOutstandingJobAndMarksItCompleted(): void
    {
        $ownerId = $this->insertOwner($this->ulid('1'));
        $this->insertEntry($this->ulid('2'), $ownerId->toString());

        // Simulate a job left behind by a prior failed attempt: create it
        // directly, without the user row having been purged yet.
        $this->jobRepository->create($ownerId, $this->clock);

        $report = $this->service->runPurgeSlice(10);

        self::assertSame(1, $report->attempted);
        self::assertSame(1, $report->succeeded);
        self::assertSame(0, $report->failed);

        self::assertSame(0, $this->countRows('users'));
        self::assertSame(0, $this->countRows('diary_entries'));

        $jobRow = $this->pdo->query('SELECT completed_at FROM purge_jobs')->fetch(PDO::FETCH_ASSOC);
        self::assertNotNull($jobRow['completed_at']);
    }

    public function testRunPurgeSliceIsIdempotentWhenRunTwiceOnAnAlreadyPurgedUser(): void
    {
        $ownerId = $this->insertOwner($this->ulid('1'));
        $this->insertEntry($this->ulid('2'), $ownerId->toString());
        $this->jobRepository->create($ownerId, $this->clock);

        $first = $this->service->runPurgeSlice(10);
        self::assertSame(1, $first->succeeded);

        // Simulate a second outstanding job for the same (already-purged)
        // user, as could happen if a duplicate request slipped through.
        $this->jobRepository->create($ownerId, $this->clock);

        $second = $this->service->runPurgeSlice(10);

        // The user and entry rows no longer exist, so every DELETE in the
        // second attempt matches zero rows - a harmless no-op - and the
        // second job still completes successfully.
        self::assertSame(1, $second->attempted);
        self::assertSame(1, $second->succeeded);
        self::assertSame(0, $second->failed);
    }

    public function testRunPurgeSliceIsBoundedByTheGivenLimit(): void
    {
        $ownerA = $this->insertOwner($this->ulid('1'), 'a@example.com');
        $ownerB = $this->insertOwner($this->ulid('2'), 'b@example.com');
        $ownerC = $this->insertOwner($this->ulid('3'), 'c@example.com');

        $this->jobRepository->create($ownerA, $this->clock);
        $this->clock->advanceMinutes(1);
        $this->jobRepository->create($ownerB, $this->clock);
        $this->clock->advanceMinutes(1);
        $this->jobRepository->create($ownerC, $this->clock);

        $report = $this->service->runPurgeSlice(2);

        self::assertSame(2, $report->attempted);
        self::assertSame(2, $report->succeeded);

        // The oldest two requests (A and B) were purged; C, requested last, was
        // left for a later run.
        self::assertNull($this->findUser($ownerA->toString()));
        self::assertNull($this->findUser($ownerB->toString()));
        self::assertNotNull($this->findUser($ownerC->toString()));

        self::assertSame(1, $this->countRows('purge_jobs', 'completed_at IS NULL'));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findUser(string $id): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM users WHERE id = :id');
        $statement->execute([':id' => $id]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }
}
