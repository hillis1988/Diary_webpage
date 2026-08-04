<?php

declare(strict_types=1);

namespace Diary\Storage;

use Diary\Auth\UserId;

/**
 * One outstanding row of `purge_jobs`: enough for {@see PurgeService} to retry
 * the purge it names (Requirement 4.5).
 *
 * Deliberately thin - `requested_at`, `attempt_count` and `last_error` are
 * bookkeeping {@see PurgeJobRepository} writes and reads directly; a retry
 * only needs the job's id (to update it) and the user id (to purge).
 */
final class PurgeJob
{
    public function __construct(
        public readonly string $id,
        public readonly UserId $userId,
    ) {
    }
}
