<?php

declare(strict_types=1);

namespace Diary\Milestone;

use Diary\Access\OwnerId;
use Diary\Support\LocalDate;
use DateTimeImmutable;

/**
 * A milestone as it exists in storage: a validated {@see MilestoneInput} plus
 * the identity and bookkeeping columns only the repository knows about.
 *
 * Mirrors {@see \Diary\Diary\DiaryEntry}'s shape.
 */
final class Milestone
{
    private function __construct(
        private readonly MilestoneId $id,
        private readonly OwnerId $owner,
        private readonly MilestoneInput $input,
        private readonly DateTimeImmutable $createdAt,
        private readonly DateTimeImmutable $updatedAt,
    ) {
    }

    public static function of(
        MilestoneId $id,
        OwnerId $owner,
        MilestoneInput $input,
        DateTimeImmutable $createdAt,
        DateTimeImmutable $updatedAt,
    ): self {
        return new self($id, $owner, $input, $createdAt, $updatedAt);
    }

    public function id(): MilestoneId
    {
        return $this->id;
    }

    public function ownerId(): OwnerId
    {
        return $this->owner;
    }

    public function input(): MilestoneInput
    {
        return $this->input;
    }

    public function date(): LocalDate
    {
        return $this->input->date();
    }

    public function description(): string
    {
        return $this->input->description();
    }

    public function category(): MilestoneCategory
    {
        return $this->input->category();
    }

    public function createdAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function updatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
