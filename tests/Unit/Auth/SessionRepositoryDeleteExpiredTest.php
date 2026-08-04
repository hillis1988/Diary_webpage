<?php

declare(strict_types=1);

namespace Diary\Tests\Unit\Auth;

use DateTimeImmutable;
use Diary\Auth\SessionRepository;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * `SessionRepository::deleteExpired`: bounded housekeeping for the
 * `/cron/sessions` endpoint (Requirement 4.2's design table row - "delete
 * sessions terminated or idle beyond retention").
 */
final class SessionRepositoryDeleteExpiredTest extends TestCase
{
    private PDO $pdo;
    private SessionRepository $sessions;

    protected function setUp(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_sqlite is not loaded.');
        }

        $this->pdo = SqliteAuthTables::connection();
        $this->sessions = new SessionRepository($this->pdo);

        $this->insertUser('u1');
    }

    private function insertUser(string $id): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO users (id, email_normalized, email_display, password_hash, role, data_owner_id,
                status, failed_login_count, locked_until, deletion_requested_at, created_at, updated_at)
             VALUES (:id, :email, :email, \'hash\', \'owner\', :id, \'active\', 0, NULL, NULL, :now, :now)'
        );
        $statement->execute([
            ':id' => $id,
            ':email' => $id . '@example.com',
            ':now' => '2025-03-01 09:00:00',
        ]);
    }

    private function insertSession(
        string $id,
        ?string $terminatedAt,
        string $lastActivityAt = '2025-03-01 09:00:00',
    ): void {
        $statement = $this->pdo->prepare(
            'INSERT INTO sessions (id, user_id, context_role, data_owner_id, created_at, last_activity_at, terminated_at)
             VALUES (:id, \'u1\', \'owner\', \'u1\', :created_at, :last_activity_at, :terminated_at)'
        );
        $statement->execute([
            ':id' => $id,
            ':created_at' => '2025-03-01 08:00:00',
            ':last_activity_at' => $lastActivityAt,
            ':terminated_at' => $terminatedAt,
        ]);
    }

    private function sessionExists(string $id): bool
    {
        $statement = $this->pdo->prepare('SELECT 1 FROM sessions WHERE id = :id');
        $statement->execute([':id' => $id]);

        return $statement->fetchColumn() !== false;
    }

    public function testDeletesASessionTerminatedBeforeTheCutoff(): void
    {
        $this->insertSession('s1', terminatedAt: '2025-02-01 00:00:00');

        $deleted = $this->sessions->deleteExpired(new DateTimeImmutable('2025-03-01 00:00:00'), 100);

        self::assertSame(1, $deleted);
        self::assertFalse($this->sessionExists('s1'));
    }

    public function testKeepsASessionTerminatedAfterTheCutoff(): void
    {
        $this->insertSession('s1', terminatedAt: '2025-03-02 00:00:00');

        $deleted = $this->sessions->deleteExpired(new DateTimeImmutable('2025-03-01 00:00:00'), 100);

        self::assertSame(0, $deleted);
        self::assertTrue($this->sessionExists('s1'));
    }

    public function testDeletesALiveSessionIdleBeyondTheCutoff(): void
    {
        $this->insertSession('s1', terminatedAt: null, lastActivityAt: '2025-02-01 00:00:00');

        $deleted = $this->sessions->deleteExpired(new DateTimeImmutable('2025-03-01 00:00:00'), 100);

        self::assertSame(1, $deleted);
        self::assertFalse($this->sessionExists('s1'));
    }

    public function testKeepsALiveSessionThatIsStillWithinTheIdleCutoff(): void
    {
        $this->insertSession('s1', terminatedAt: null, lastActivityAt: '2025-03-02 00:00:00');

        $deleted = $this->sessions->deleteExpired(new DateTimeImmutable('2025-03-01 00:00:00'), 100);

        self::assertSame(0, $deleted);
        self::assertTrue($this->sessionExists('s1'));
    }

    public function testIsBoundedByTheGivenLimit(): void
    {
        $this->insertSession('s1', terminatedAt: '2025-02-01 00:00:00');
        $this->insertSession('s2', terminatedAt: '2025-02-01 00:00:00');
        $this->insertSession('s3', terminatedAt: '2025-02-01 00:00:00');

        $deleted = $this->sessions->deleteExpired(new DateTimeImmutable('2025-03-01 00:00:00'), 2);

        self::assertSame(2, $deleted);
        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM sessions')->fetchColumn());
    }

    public function testIsIdempotentWhenRunTwiceInARow(): void
    {
        $this->insertSession('s1', terminatedAt: '2025-02-01 00:00:00');

        $first = $this->sessions->deleteExpired(new DateTimeImmutable('2025-03-01 00:00:00'), 100);
        $second = $this->sessions->deleteExpired(new DateTimeImmutable('2025-03-01 00:00:00'), 100);

        self::assertSame(1, $first);
        self::assertSame(0, $second);
    }
}
