<?php

declare(strict_types=1);

namespace Diary\Diary;

use Diary\Access\OwnerId;
use Diary\Support\LocalDate;
use DateTimeImmutable;

/**
 * A diary entry as it exists in storage: a validated {@see DiaryEntryInput}
 * plus the identity and bookkeeping columns only the repository knows about.
 *
 * The id matters beyond this record. `cbt_recommendations` links to an entry by
 * this id, so a stable id across a submission's lifetime (unchanged by a later
 * submission that updates the same date) is what keeps that link correct.
 */
final class DiaryEntry
{
    private function __construct(
        private readonly string $id,
        private readonly OwnerId $owner,
        private readonly DiaryEntryInput $input,
        private readonly DateTimeImmutable $createdAt,
        private readonly DateTimeImmutable $updatedAt,
    ) {
    }

    public static function of(
        string $id,
        OwnerId $owner,
        DiaryEntryInput $input,
        DateTimeImmutable $createdAt,
        DateTimeImmutable $updatedAt,
    ): self {
        return new self($id, $owner, $input, $createdAt, $updatedAt);
    }

    /** The stable primary key `cbt_recommendations.entry_id` links to. */
    public function id(): string
    {
        return $this->id;
    }

    public function ownerId(): OwnerId
    {
        return $this->owner;
    }

    public function input(): DiaryEntryInput
    {
        return $this->input;
    }

    public function date(): LocalDate
    {
        return $this->input->date();
    }

    /** Unchanged by a later submission that updates this same date. */
    public function createdAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    /** The moment of the most recent accepted submission for this date. */
    public function updatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
