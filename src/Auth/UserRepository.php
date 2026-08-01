<?php

declare(strict_types=1);

namespace Diary\Auth;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PDOException;
use PDOStatement;

/**
 * Every read and write of the `users` table.
 *
 * Rules this class holds to, because the rest of the authentication code depends
 * on them:
 *
 * - Values only ever reach SQL as bound parameters. No identifier or value is
 *   interpolated into a statement, so no input can change the shape of a query.
 * - Lookups by email take the already-normalised form. The column has a binary
 *   collation, so a lookup matches exactly the string the uniqueness check saw
 *   (Requirement 1.2).
 * - `insert` is a plain INSERT. It never upserts, so a duplicate registration
 *   cannot overwrite an existing account's hash or timestamps; the unique index
 *   rejects the row and that is surfaced as {@see DuplicateEmailException}.
 * - Times are written and read as UTC `Y-m-d H:i:s`, matching the DATETIME
 *   columns, which carry no zone of their own.
 */
final class UserRepository
{
    /** The format the DATETIME columns are written in; UTC throughout. */
    public const DATETIME_FORMAT = 'Y-m-d H:i:s';

    private const COLUMNS = 'id, email_normalized, email_display, password_hash, role, data_owner_id, '
        . 'status, failed_login_count, locked_until, deletion_requested_at, created_at, updated_at';

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * The account for an address, or null when there is none.
     *
     * @param string $email raw or normalised; it is normalised here either way so
     *                      no caller can accidentally look up an unnormalised form
     */
    public function findByEmail(string $email): ?UserAccount
    {
        return $this->findByNormalizedEmail(EmailAddress::normalise($email));
    }

    public function findByNormalizedEmail(string $normalizedEmail): ?UserAccount
    {
        $statement = $this->pdo->prepare(
            'SELECT ' . self::COLUMNS . ' FROM users WHERE email_normalized = :email LIMIT 1'
        );
        $statement->execute([':email' => $normalizedEmail]);

        return $this->hydrateOne($statement);
    }

    public function findById(UserId|string $id): ?UserAccount
    {
        $statement = $this->pdo->prepare('SELECT ' . self::COLUMNS . ' FROM users WHERE id = :id LIMIT 1');
        $statement->execute([':id' => (string) $id]);

        return $this->hydrateOne($statement);
    }

    /**
     * Whether an address already has an account. Used before registration writes
     * anything, so the ordinary duplicate case never reaches the unique index.
     */
    public function existsWithNormalizedEmail(string $normalizedEmail): bool
    {
        $statement = $this->pdo->prepare('SELECT 1 FROM users WHERE email_normalized = :email LIMIT 1');
        $statement->execute([':email' => $normalizedEmail]);

        return $statement->fetchColumn() !== false;
    }

    /**
     * Insert one account exactly as given.
     *
     * An owner's `data_owner_id` points at its own id. That self-reference is
     * satisfied within the single row being inserted, so the foreign key holds
     * without a second statement.
     *
     * @throws DuplicateEmailException when the unique index on `email_normalized`
     *                                 rejects the row
     */
    public function insert(UserAccount $account): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO users (' . self::COLUMNS . ') VALUES '
            . '(:id, :email_normalized, :email_display, :password_hash, :role, :data_owner_id, '
            . ':status, :failed_login_count, :locked_until, :deletion_requested_at, :created_at, :updated_at)'
        );

        try {
            $statement->execute([
                ':id' => $account->id->toString(),
                ':email_normalized' => $account->emailNormalized,
                ':email_display' => $account->emailDisplay,
                ':password_hash' => $account->passwordHash,
                ':role' => $account->role->value,
                ':data_owner_id' => $account->dataOwnerId->toString(),
                ':status' => $account->status->value,
                ':failed_login_count' => $account->failedLoginCount,
                ':locked_until' => self::formatDateTime($account->lockedUntil),
                ':deletion_requested_at' => self::formatDateTime($account->deletionRequestedAt),
                ':created_at' => self::formatDateTime($account->createdAt),
                ':updated_at' => self::formatDateTime($account->updatedAt),
            ]);
        } catch (PDOException $exception) {
            if (self::isDuplicateEmail($exception)) {
                throw new DuplicateEmailException('That email address already has an account.', 0, $exception);
            }

            throw $exception;
        }
    }

    /**
     * Record one failed sign-in: the new consecutive-failure count and, when that
     * count has reached the limit, the instant the lock expires (Requirement 2.3).
     *
     * The lock is written in the same statement as the counter, so a fifth failure
     * cannot leave a count of five with no lock behind it.
     *
     * @param int $failedLoginCount clamped to the TINYINT UNSIGNED range, so a
     *                              pathological run of failures cannot overflow the column
     */
    public function recordFailedLogin(
        UserId|string $id,
        int $failedLoginCount,
        ?DateTimeImmutable $lockedUntil,
        DateTimeImmutable $now,
    ): void {
        $statement = $this->pdo->prepare(
            'UPDATE users SET failed_login_count = :failed_login_count, locked_until = :locked_until, '
            . 'updated_at = :updated_at WHERE id = :id'
        );

        $statement->execute([
            ':failed_login_count' => max(0, min(255, $failedLoginCount)),
            ':locked_until' => self::formatDateTime($lockedUntil),
            ':updated_at' => self::formatDateTime($now),
            ':id' => (string) $id,
        ]);
    }

    /**
     * A successful sign-in: the consecutive-failure count goes back to zero and any
     * lock is cleared (Requirement 2.3).
     */
    public function resetFailedLogins(UserId|string $id, DateTimeImmutable $now): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE users SET failed_login_count = 0, locked_until = NULL, updated_at = :updated_at WHERE id = :id'
        );

        $statement->execute([
            ':updated_at' => self::formatDateTime($now),
            ':id' => (string) $id,
        ]);
    }

    public static function formatDateTime(?DateTimeImmutable $moment): ?string
    {
        return $moment?->setTimezone(new DateTimeZone('UTC'))->format(self::DATETIME_FORMAT);
    }

    private function hydrateOne(PDOStatement $statement): ?UserAccount
    {
        /** @var array<string, mixed>|false $row */
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : self::hydrate($row);
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function hydrate(array $row): UserAccount
    {
        $passwordHash = $row['password_hash'];

        return new UserAccount(
            id: UserId::fromString((string) $row['id']),
            emailNormalized: (string) $row['email_normalized'],
            emailDisplay: (string) $row['email_display'],
            passwordHash: $passwordHash === null || $passwordHash === '' ? null : (string) $passwordHash,
            role: UserRole::from((string) $row['role']),
            dataOwnerId: UserId::fromString((string) $row['data_owner_id']),
            status: UserStatus::from((string) $row['status']),
            failedLoginCount: (int) $row['failed_login_count'],
            lockedUntil: self::parseDateTime($row['locked_until']),
            deletionRequestedAt: self::parseDateTime($row['deletion_requested_at']),
            createdAt: self::parseDateTime($row['created_at']) ?? new DateTimeImmutable('@0'),
            updatedAt: self::parseDateTime($row['updated_at']) ?? new DateTimeImmutable('@0'),
        );
    }

    private static function parseDateTime(mixed $value): ?DateTimeImmutable
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        $utc = new DateTimeZone('UTC');
        $parsed = DateTimeImmutable::createFromFormat('!' . self::DATETIME_FORMAT, $value, $utc);

        return $parsed === false ? new DateTimeImmutable($value, $utc) : $parsed;
    }

    /**
     * MariaDB and SQLite both report a unique violation as SQLSTATE 23000 and both
     * name the offending index or column, which is what distinguishes a duplicate
     * email from any other constraint failure on this table.
     */
    private static function isDuplicateEmail(PDOException $exception): bool
    {
        if ($exception->getCode() !== '23000') {
            return false;
        }

        $message = strtolower($exception->getMessage() . ' ' . implode(' ', array_map(
            static fn (mixed $part): string => is_scalar($part) ? (string) $part : '',
            $exception->errorInfo ?? []
        )));

        return str_contains($message, 'email_normalized');
    }
}
