<?php

declare(strict_types=1);

namespace Diary\Tests\Unit\Auth;

use Diary\Storage\ConnectionFactory;
use PDO;

/**
 * The three tables authentication touches - `users`, `sessions` and `audit_log` -
 * in memory, for unit tests.
 *
 * They mirror migrations/001, 002 and 008 in the ways the authentication code
 * depends on: the closed sets behind the ENUM columns, the foreign key from a
 * session to its user, and the absence of any foreign key on `audit_log` (a purge
 * nulls its identifiers rather than cascading the row away).
 *
 * Applying the real MariaDB migrations is the integration suite's job; this keeps
 * the unit suite runnable with no database server.
 */
final class SqliteAuthTables
{
    public static function connection(): PDO
    {
        $pdo = ConnectionFactory::fromDsn('sqlite::memory:');
        $pdo->exec('PRAGMA foreign_keys = ON');

        SqliteUsersTable::create($pdo);
        self::createSessions($pdo);
        self::createAuditLog($pdo);

        return $pdo;
    }

    public static function createSessions(PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE sessions (
                id               TEXT NOT NULL PRIMARY KEY,
                user_id          TEXT NOT NULL REFERENCES users (id) ON DELETE CASCADE,
                context_role     TEXT NOT NULL CHECK (context_role IN (\'owner\', \'viewer\')),
                data_owner_id    TEXT NOT NULL,
                created_at       TEXT NOT NULL,
                last_activity_at TEXT NOT NULL,
                terminated_at    TEXT     NULL
            )'
        );
    }

    public static function createAuditLog(PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE audit_log (
                id            TEXT NOT NULL PRIMARY KEY,
                actor_user_id TEXT     NULL,
                context_role  TEXT NOT NULL DEFAULT \'anonymous\'
                                   CHECK (context_role IN (\'owner\', \'viewer\', \'anonymous\')),
                action        TEXT NOT NULL,
                target_type   TEXT     NULL,
                target_id     TEXT     NULL,
                outcome       TEXT NOT NULL CHECK (outcome IN (\'success\', \'failure\', \'denied\')),
                ip_hash       TEXT     NULL,
                occurred_at   TEXT NOT NULL
            )'
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function sessions(PDO $pdo): array
    {
        return self::rows($pdo, 'SELECT * FROM sessions ORDER BY created_at, id');
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function auditLog(PDO $pdo): array
    {
        return self::rows($pdo, 'SELECT * FROM audit_log ORDER BY id');
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function rows(PDO $pdo, string $sql): array
    {
        $statement = $pdo->query($sql);

        /** @var list<array<string, mixed>> $rows */
        $rows = $statement === false ? [] : $statement->fetchAll(PDO::FETCH_ASSOC);

        return $rows;
    }
}
