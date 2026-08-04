<?php

declare(strict_types=1);

namespace Diary\Storage;

/**
 * What one `runPurgeSlice` call did: how many outstanding jobs it retried,
 * how many of those completed, and how many failed again (Requirement 4.5).
 *
 * A failed job stays outstanding for the next run; nothing here needs to say
 * more than the counts, since the row itself already carries the diagnostic
 * (`purge_jobs.last_error`).
 */
final class PurgeReport
{
    private function __construct(
        public readonly int $attempted,
        public readonly int $succeeded,
        public readonly int $failed,
    ) {
    }

    public static function of(int $attempted, int $succeeded, int $failed): self
    {
        return new self($attempted, $succeeded, $failed);
    }

    public static function empty(): self
    {
        return new self(0, 0, 0);
    }
}
