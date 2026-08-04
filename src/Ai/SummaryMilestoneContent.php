<?php

declare(strict_types=1);

namespace Diary\Ai;

use Diary\Milestone\Milestone;
use Diary\Milestone\MilestoneCategory;
use Diary\Support\LocalDate;
use JsonSerializable;

/**
 * The pseudonymised content of one Milestone, exactly as handed to a
 * {@see SummaryProvider} so trends can be related to it (Requirement 9.3).
 *
 * Mirrors {@see SummaryEntryContent}'s precedent: date, description and
 * category only - no owner id and no milestone id.
 */
final class SummaryMilestoneContent implements JsonSerializable
{
    public function __construct(
        private readonly LocalDate $date,
        private readonly string $description,
        private readonly MilestoneCategory $category,
    ) {
    }

    public static function fromMilestone(Milestone $milestone): self
    {
        return new self($milestone->date(), $milestone->description(), $milestone->category());
    }

    public function date(): LocalDate
    {
        return $this->date;
    }

    public function description(): string
    {
        return $this->description;
    }

    public function category(): MilestoneCategory
    {
        return $this->category;
    }

    /**
     * The pseudonymised Summary_Milestone_Record shape sent to a
     * {@see SummaryProvider} (Requirements 1.3, 10.1).
     *
     * @return array{date: string, category: string, description: string}
     */
    public function jsonSerialize(): array
    {
        return [
            'date' => $this->date->toIso(),
            'category' => $this->category->value,
            'description' => $this->description,
        ];
    }
}
