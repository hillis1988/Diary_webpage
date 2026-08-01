<?php

declare(strict_types=1);

namespace Diary\Auth;

use DateTimeImmutable;
use Diary\Storage\SqlTimestamp;
use PDO;

/**
 * Reads and writes of the `sessions` table.
 *
 * As with {@see UserRepository}, values reach SQL only as bound parameters, and
 * DATETIME values are UTC `Y-m-d H:i:s`.
 *
 * Only the identifier of a session is ever written; the cookie token itself is
 * hashed into that identifier by {@see SessionId::fromToken()} and never leaves
 * the request that issued it.
 */
final class SessionRepository
{
    private const COLUMNS = 'id, user_id, context_role, data_owner_id, created_at, last_activity_at, terminated_at';

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function insert(Session $session): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO sessions (' . self::COLUMNS . ') VALUES '
            . '(:id, :user_id, :context_role, :data_owner_id, :created_at, :last_activity_at, :terminated_at)'
        );

        $statement->execute([
            ':id' => $session->id->toString(),
            ':user_id' => $session->userId->toString(),
            // Frozen at sign-in: this is the row's own copy of the role, not a
            // reference back to users.role (Requirement 7.3).
            ':context_role' => $session->contextRole->value,
            ':data_owner_id' => $session->dataOwnerId->toString(),
            ':created_at' => SqlTimestamp::format($session->createdAt),
            ':last_activity_at' => SqlTimestamp::format($session->lastActivityAt),
            ':terminated_at' => SqlTimestamp::format($session->terminatedAt),
        ]);
    }

    public function findById(SessionId|string $id): ?Session
    {
        $statement = $this->pdo->prepare('SELECT ' . self::COLUMNS . ' FROM sessions WHERE id = :id LIMIT 1');
        $statement->execute([':id' => (string) $id]);

        /** @var array<string, mixed>|false $row */
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : self::hydrate($row);
    }

    /**
     * Slide `last_activity_at` forward, which is what keeps a session in use alive
     * against the idle timeout (Requirement 2.5).
     *
     * The `terminated_at IS NULL` guard means a signed-out session cannot be revived
     * by a request that raced the sign-out.
     */
    public function touch(SessionId $id, DateTimeImmutable $now): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE sessions SET last_activity_at = :now WHERE id = :id AND terminated_at IS NULL'
        );

        $statement->execute([
            ':now' => SqlTimestamp::format($now),
            ':id' => $id->toString(),
        ]);
    }

    /**
     * End a session (Requirement 2.4). Idempotent: a session already terminated keeps
     * the moment it was first terminated, so re-running a sign-out cannot move the
     * record of when access actually ended.
     *
     * @return bool whether this call was the one that ended it
     */
    public function terminate(SessionId $id, DateTimeImmutable $now): bool
    {
        $statement = $this->pdo->prepare(
            'UPDATE sessions SET terminated_at = :now WHERE id = :id AND terminated_at IS NULL'
        );

        $statement->execute([
            ':now' => SqlTimestamp::format($now),
            ':id' => $id->toString(),
        ]);

        return $statement->rowCount() > 0;
    }

    /**
     * End every live session belonging to one account.
     *
     * Revoking a viewer has to take effect at once rather than at their next sign-in
     * (Requirement 7.4), and so does a password change; both go through here.
     *
     * @return int how many live sessions were ended
     */
    public function terminateAllForUser(UserId $userId, DateTimeImmutable $now): int
    {
        $statement = $this->pdo->prepare(
            'UPDATE sessions SET terminated_at = :now WHERE user_id = :user_id AND terminated_at IS NULL'
        );

        $statement->execute([
            ':now' => SqlTimestamp::format($now),
            ':user_id' => $userId->toString(),
        ]);

        return $statement->rowCount();
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function hydrate(array $row): Session
    {
        return new Session(
            id: SessionId::fromString((string) $row['id']),
            userId: UserId::fromString((string) $row['user_id']),
            contextRole: UserRole::from((string) $row['context_role']),
            dataOwnerId: UserId::fromString((string) $row['data_owner_id']),
            createdAt: SqlTimestamp::parse($row['created_at']) ?? new DateTimeImmutable('@0'),
            lastActivityAt: SqlTimestamp::parse($row['last_activity_at']) ?? new DateTimeImmutable('@0'),
            terminatedAt: SqlTimestamp::parse($row['terminated_at']),
        );
    }
}
