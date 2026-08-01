<?php

declare(strict_types=1);

namespace Diary\Storage;

use Diary\Auth\UserId;
use Diary\Support\Clock;
use Diary\Support\Ulid;
use PDO;

/**
 * Reads and writes of the `purge_jobs` table: retry bookkeeping for account
 * deletion (Requirement 4.5).
 *
 * `user_id` deliberately carries no foreign key (see migrations/007) because
 * the user row is deleted before this job's purge attempt can be known to
 * have succeeded, and the job must outlive it in order to be retried. As with
 * every other repository, values reach SQL only as bound parameters.
 */
final class PurgeJobRepository
{
    /** `purge_jobs.last_error` is VARCHAR(1000); never diary or milestone content. */
    private const MAX_LAST_ERROR_LENGTH = 1000;

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Record a fresh deletion request. One user may accumulate more than one
     * row only if a previous job somehow never completed and a second
     * request arrives; `runPurgeSlice` treats every outstanding row for a
     * user identically; a purge with no matching rows left is a no-op.
     */
    public function create(UserId $userId, Clock $clock): PurgeJob
    {
        $id = Ulid::generate($clock);

        $statement = $this->pdo->prepare(
            'INSERT INTO purge_jobs (id, user_id, requested_at, completed_at, attempt_count, last_error)
             VALUES (:id, :user_id, :requested_at, NULL, 0, NULL)'
        );
        $statement->bindValue(':id', $id);
        $statement->bindValue(':user_id', $userId->toString());
        $statement->bindValue(':requested_at', SqlTimestamp::format($clock->now()));
        $statement->execute();

        return new PurgeJob($id, $userId);
    }

    /**
     * Mark a job's purge as having fully succeeded.
     */
    public function markCompleted(string $jobId, Clock $clock): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE purge_jobs SET completed_at = :completed_at WHERE id = :id'
        );
        $statement->bindValue(':completed_at', SqlTimestamp::format($clock->now()));
        $statement->bindValue(':id', $jobId);
        $statement->execute();
    }

    /**
     * Record a failed attempt: `attempt_count` advances and `last_error`
     * carries a short diagnostic, but the row is left outstanding
     * (`completed_at` stays NULL) so it can be retried.
     */
    public function recordFailure(string $jobId, string $error): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE purge_jobs
             SET attempt_count = attempt_count + 1, last_error = :last_error
             WHERE id = :id'
        );
        $statement->bindValue(':last_error', mb_substr($error, 0, self::MAX_LAST_ERROR_LENGTH));
        $statement->bindValue(':id', $jobId);
        $statement->execute();
    }

    /**
     * Outstanding jobs (`completed_at IS NULL`), oldest request first, up to
     * `$limit` rows - the unit `runPurgeSlice` retries per cron run.
     *
     * @return list<PurgeJob>
     */
    public function findOutstanding(int $limit): array
    {
        $limit = max(0, $limit);

        if ($limit === 0) {
            return [];
        }

        $statement = $this->pdo->prepare(
            'SELECT id, user_id FROM purge_jobs
             WHERE completed_at IS NULL
             ORDER BY requested_at ASC, id ASC
             LIMIT :limit'
        );
        $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
        $statement->execute();

        $jobs = [];
        /** @var array<string, mixed> $row */
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $jobs[] = new PurgeJob((string) $row['id'], UserId::fromString((string) $row['user_id']));
        }

        return $jobs;
    }
}
