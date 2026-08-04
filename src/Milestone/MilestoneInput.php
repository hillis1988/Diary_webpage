<?php

declare(strict_types=1);

namespace Diary\Milestone;

use Diary\Support\LocalDate;
use InvalidArgumentException;

/**
 * A milestone submission that has already been validated: the shape
 * `Milestone_Service::create()`/`update()` accept.
 *
 * Mirrors {@see \Diary\Diary\DiaryEntryInput}: construction throws rather than
 * returning a Result, because arriving here with a blank description means a
 * caller skipped {@see MilestoneInputValidator}, which is a programming
 * mistake, not a user one.
 */
final class MilestoneInput
{
    private function __construct(
        private readonly LocalDate $date,
        private readonly string $description,
        private readonly MilestoneCategory $category,
    ) {
    }

    public static function of(LocalDate $date, string $description, MilestoneCategory $category): self
    {
        if (trim($description) === '') {
            throw new InvalidArgumentException('A milestone description must not be blank.');
        }

        return new self($date, $description, $category);
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
     * The content half of the encrypted milestone payload. `schema_version` is
     * the storage layer's business and is not added here.
     *
     * @return array<string, string>
     */
    public function toPayload(): array
    {
        return [
            'description' => $this->description,
            'category' => $this->category->value,
        ];
    }
}
