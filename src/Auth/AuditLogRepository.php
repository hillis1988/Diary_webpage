<?php

declare(strict_types=1);

namespace Diary\Auth;

use Diary\Storage\SqlTimestamp;
use PDO;

/**
 * The writer for `audit_log`.
 *
 * It only appends. Nothing here updates or deletes a row, so the trail behind a
 * lockout (Requirement 2.3) cannot be edited by the code that produced it; a
 * purge later nulls the identifiers rather than removing the row.
 *
 * The table takes no encryption because it holds no health data - see
 * {@see AuditLogEntry}, which is shaped so there is nowhere to put any.
 */
final class AuditLogRepository
{
    private const COLUMNS = 'id, actor_user_id, context_role, action, target_type, target_id, '
        . 'outcome, ip_hash, occurred_at';

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function record(AuditLogEntry $entry): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO audit_log (' . self::COLUMNS . ') VALUES '
            . '(:id, :actor_user_id, :context_role, :action, :target_type, :target_id, '
            . ':outcome, :ip_hash, :occurred_at)'
        );

        $statement->execute([
            ':id' => $entry->id,
            ':actor_user_id' => $entry->actorUserId?->toString(),
            ':context_role' => $entry->contextRole->value,
            ':action' => $entry->action->value,
            ':target_type' => $entry->targetType,
            ':target_id' => $entry->targetId?->toString(),
            ':outcome' => $entry->outcome->value,
            ':ip_hash' => $entry->ipHash,
            ':occurred_at' => SqlTimestamp::format($entry->occurredAt),
        ]);
    }
}
