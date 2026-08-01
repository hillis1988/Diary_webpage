<?php

declare(strict_types=1);

namespace Diary\Tests\Unit\Auth;

use Diary\Storage\ConnectionFactory;
use PDO;

/**
 * An in-memory `users` table for unit tests.
 *
 * It mirrors migrations/001_create_users.sql in the ways the authentication code
 * depends on: the unique index on `email_normalized`, binary comparison of that
 * column (SQLite compares TEXT byte-wise by default, which is what
 * utf8mb4_bin gives us in MariaDB), the closed sets behind the two ENUM columns,
 * and the self-referential foreign key on `data_owner_id`. Foreign keys are
 * switched on, so an owner row inserted with `data_owner_id` set to its own id
 * has to satisfy that constraint here as well.
 *
 * Applying the real MariaDB migration is the integration suite's job; this keeps
 * the unit suite runnable with no database server.
 */
final class SqliteUsersTable
{
    public static function connection(): PDO
    {
        $pdo = ConnectionFactory::fromDsn('sqlite::memory:');
        $pdo->exec('PRAGMA foreign_keys = ON');
        self::create($pdo);

        return $pdo;
    }

    public static function create(PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE users (
                id                    TEXT    NOT NULL PRIMARY KEY,
                email_normalized      TEXT    NOT NULL COLLATE BINARY,
                email_display         TEXT    NOT NULL,
                password_hash         TEXT        NULL,
                role                  TEXT    NOT NULL CHECK (role IN (\'owner\', \'viewer\')),
                data_owner_id         TEXT    NOT NULL REFERENCES users (id) ON DELETE CASCADE,
                status                TEXT    NOT NULL DEFAULT \'invited\'
                                              CHECK (status IN (\'invited\', \'active\', \'revoked\')),
                failed_login_count    INTEGER NOT NULL DEFAULT 0,
                locked_until          TEXT        NULL,
                deletion_requested_at TEXT        NULL,
                created_at            TEXT    NOT NULL,
                updated_at            TEXT    NOT NULL
            )'
        );

        $pdo->exec('CREATE UNIQUE INDEX uq_users_email_normalized ON users (email_normalized)');
    }

    /**
     * Every row, ordered by id, as a snapshot to compare before and after an
     * operation that is supposed to change nothing.
     *
     * @return list<array<string, mixed>>
     */
    public static function snapshot(PDO $pdo): array
    {
        $rows = $pdo->query('SELECT * FROM users ORDER BY id');

        return $rows === false ? [] : $rows->fetchAll(PDO::FETCH_ASSOC);
    }
}
