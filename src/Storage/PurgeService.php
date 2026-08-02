<?php

declare(strict_types=1);

namespace Diary\Storage;

use Diary\Auth\UserId;
use Diary\Auth\UserRole;
use Diary\Support\Clock;
use Diary\Support\Result;
use PDO;
use Throwable;

/**
 * Account deletion (Requirement 4.5).
 *
 * `requestDeletion` sets `users.deletion_requested_at`, records a
 * `purge_jobs` row, then attempts the whole purge immediately in one
 * transaction: recommendations, entries, milestones, linked viewer accounts,
 * sessions, then the user row itself - exactly the order design.md's
 * "Deletion (Requirement 4.5)" decision names. `audit_log` is retained but
 * stripped of any reference to the deleted content: `actor_user_id` and
 * `target_id` are nulled wherever they name the deleted user or one of their
 * viewers, per migrations/008's note that those columns carry no foreign key
 * for exactly that reason.
 *
 * If the immediate attempt fails partway, the transaction rolls back in
 * full and the `purge_jobs` row is left outstanding (with `attempt_count`
 * and `last_error` updated) for `runPurgeSlice` - the daily cron slice - to
 * retry. Every delete here is a `DELETE ... WHERE` scoped by id, never an
 * assumption that a row exists, so retrying a partially-completed purge is a
 * harmless no-op for whatever already went: idempotency falls out of using
 * `DELETE` rather than needing its own bookkeeping.
 *
 * Like {@see \Diary\Milestone\MilestoneService} and
 * {@see \Diary\Access\ViewerAccessService}, this takes identifiers directly
 * rather than a `SecurityContext`; the account-deletion controller (a later
 * task) owns resolving the signed-in user's id and calling
 * `AccessControlService::authorise()` with `OperationKind::ManageAccess`
 * before reaching `requestDeletion`.
 *
 * Takes a raw {@see PDO} rather than composing repository calls: the purge
 * spans `cbt_recommendations`, `diary_entries`, `milestones`, `users`,
 * `sessions` and `audit_log` and has to be one all-or-nothing transaction, so
 * issuing bound `DELETE`/`UPDATE` statements directly here - the same
 * bound-parameter discipline every repository in this codebase follows - is
 * simpler than threading one shared transaction through five repositories.
 */
final class PurgeService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly PurgeJobRepository $jobs,
        private readonly Clock $clock,
    ) {
    }

    /**
     * @return Result<null>
     */
    public function requestDeletion(UserId $userId, Clock $clock): Result
    {
        $now = $clock->now();

        // Two distinct placeholders for the same value: a native prepared
        // statement (EMULATE_PREPARES off, as ConnectionFactory configures for
        // MariaDB) rejects a named placeholder bound twice in one statement.
        $statement = $this->pdo->prepare(
            'UPDATE users SET deletion_requested_at = :now, updated_at = :now_again WHERE id = :id'
        );
        $statement->bindValue(':now', SqlTimestamp::format($now));
        $statement->bindValue(':now_again', SqlTimestamp::format($now));
        $statement->bindValue(':id', $userId->toString());
        $statement->execute();

        if ($statement->rowCount() === 0) {
            return Result::failure('user_not_found', 'That account could not be found.');
        }

        $job = $this->jobs->create($userId, $clock);

        // Best-effort immediate purge (Requirement 4.5's "immediate deletion"
        // decision). A failure here leaves the job outstanding for the cron
        // slice to retry; the request itself has already been recorded and
        // is not undone by an immediate-purge failure.
        $this->attemptPurge($job, $clock);

        return Result::ok(null);
    }

    /**
     * Cron-safe retry of outstanding `purge_jobs`, bounded by `$limit` so a
     * run stays well inside the IONOS cron manager's 60-second cap.
     */
    public function runPurgeSlice(int $limit): PurgeReport
    {
        $outstanding = $this->jobs->findOutstanding($limit);

        $succeeded = 0;
        $failed = 0;

        foreach ($outstanding as $job) {
            if ($this->attemptPurge($job, $this->clock)) {
                $succeeded++;
            } else {
                $failed++;
            }
        }

        return PurgeReport::of(count($outstanding), $succeeded, $failed);
    }

    /**
     * One purge attempt, in a single transaction. Returns whether it
     * succeeded; either outcome updates the `purge_jobs` row, but the
     * bookkeeping write for a failure happens only after the transaction has
     * been rolled back, so it is never itself undone by that rollback.
     */
    private function attemptPurge(PurgeJob $job, Clock $clock): bool
    {
        $ownerId = $job->userId->toString();

        try {
            $this->pdo->beginTransaction();

            // Snapshot linked viewer ids before anything is deleted, so the
            // audit-log strip below still knows which ids to look for even
            // after the viewer accounts themselves are gone.
            $viewerIds = $this->viewerIdsFor($ownerId);
            $allIds = [...$viewerIds, $ownerId];

            // recommendations
            $this->exec(
                'DELETE FROM cbt_recommendations
                 WHERE entry_id IN (SELECT id FROM diary_entries WHERE owner_id = :owner)',
                [':owner' => $ownerId]
            );

            // entries
            $this->exec('DELETE FROM diary_entries WHERE owner_id = :owner', [':owner' => $ownerId]);

            // milestones
            $this->exec('DELETE FROM milestones WHERE owner_id = :owner', [':owner' => $ownerId]);

            // linked viewer accounts' own sessions, then the viewer accounts
            if ($viewerIds !== []) {
                $this->execWithIdList(
                    'DELETE FROM sessions WHERE user_id IN (%s)',
                    $viewerIds
                );
            }

            $this->exec(
                'DELETE FROM users WHERE data_owner_id = :owner AND role = :role',
                [':owner' => $ownerId, ':role' => UserRole::Viewer->value]
            );

            // sessions for the user being deleted
            $this->exec('DELETE FROM sessions WHERE user_id = :owner', [':owner' => $ownerId]);

            // strip audit_log of references to the deleted content
            $this->execWithIdList('UPDATE audit_log SET actor_user_id = NULL WHERE actor_user_id IN (%s)', $allIds);
            $this->execWithIdList('UPDATE audit_log SET target_id = NULL WHERE target_id IN (%s)', $allIds);

            // the user row itself
            $this->exec('DELETE FROM users WHERE id = :id', [':id' => $ownerId]);

            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            $this->jobs->recordFailure($job->id, $exception->getMessage());

            return false;
        }

        $this->jobs->markCompleted($job->id, $clock);

        return true;
    }

    /**
     * @return list<string>
     */
    private function viewerIdsFor(string $ownerId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT id FROM users WHERE data_owner_id = :owner AND role = :role'
        );
        $statement->bindValue(':owner', $ownerId);
        $statement->bindValue(':role', UserRole::Viewer->value);
        $statement->execute();

        $ids = [];
        /** @var array<string, mixed> $row */
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $ids[] = (string) $row['id'];
        }

        return $ids;
    }

    /**
     * @param array<string, mixed> $params
     */
    private function exec(string $sql, array $params): void
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);
    }

    /**
     * Runs `$sqlTemplate` (containing one `%s` for the `IN (...)` list) with
     * every id bound as its own placeholder - never interpolated - so the
     * list can vary in length without ever building SQL out of values.
     *
     * @param list<string> $ids
     */
    private function execWithIdList(string $sqlTemplate, array $ids): void
    {
        if ($ids === []) {
            return;
        }

        $placeholders = [];
        $params = [];

        foreach ($ids as $index => $id) {
            $name = ':id' . $index;
            $placeholders[] = $name;
            $params[$name] = $id;
        }

        $sql = sprintf($sqlTemplate, implode(', ', $placeholders));

        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);
    }
}
