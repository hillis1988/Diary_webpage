<?php

declare(strict_types=1);

namespace Diary\Storage;

/**
 * What a migration run did. The CLI prints it; tests assert on it.
 */
final class MigrationReport
{
    /**
     * @param list<Migration> $applied            migrations applied by this run, in order
     * @param list<Migration> $alreadyApplied     migrations found already recorded, so left alone
     * @param list<int>       $recordedButMissing versions recorded in the database with no file on disk
     */
    public function __construct(
        private readonly array $applied,
        private readonly array $alreadyApplied,
        private readonly array $recordedButMissing = [],
    ) {
    }

    /**
     * @return list<Migration>
     */
    public function applied(): array
    {
        return $this->applied;
    }

    /**
     * @return list<int>
     */
    public function appliedVersions(): array
    {
        return array_map(static fn (Migration $migration): int => $migration->version(), $this->applied);
    }

    /**
     * @return list<Migration>
     */
    public function alreadyApplied(): array
    {
        return $this->alreadyApplied;
    }

    /**
     * @return list<int>
     */
    public function alreadyAppliedVersions(): array
    {
        return array_map(static fn (Migration $migration): int => $migration->version(), $this->alreadyApplied);
    }

    /**
     * @return list<int>
     */
    public function recordedButMissing(): array
    {
        return $this->recordedButMissing;
    }

    /**
     * True when the schema was already up to date: the signal that re-running is a no-op.
     */
    public function wasNoOp(): bool
    {
        return $this->applied === [];
    }
}
