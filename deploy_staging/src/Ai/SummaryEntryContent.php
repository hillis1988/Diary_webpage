<?php

declare(strict_types=1);

namespace Diary\Ai;

use Diary\Diary\DiaryEntry;
use Diary\Support\LocalDate;

/**
 * The pseudonymised content of one Diary_Entry, exactly as handed to a
 * {@see SummaryProvider} as one fact among many to narrate (Requirement 9.1).
 *
 * Mirrors {@see EntryContent}'s precedent: no owner id, no entry id - nothing
 * that could identify the person the entry belongs to. Unlike EntryContent,
 * the date is kept, because AI_Summary_Service must relate trends to
 * Milestone records within the same range (Requirement 9.3), and a narrative
 * cannot do that without knowing which day each fact belongs to. The date
 * alone, with no other identifier attached, does not narrow down who the
 * person is.
 */
final class SummaryEntryContent
{
    public function __construct(
        private readonly LocalDate $date,
        private readonly int $moodRating,
        private readonly ?int $sleepQuality,
        private readonly string $events,
        private readonly string $thoughts,
        private readonly string $emotions,
    ) {
    }

    /**
     * Strip a {@see DiaryEntry} down to the pseudonymised content a summary
     * provider may see. The entry's id and owner are intentionally left
     * behind.
     */
    public static function fromEntry(DiaryEntry $entry): self
    {
        $input = $entry->input();

        return new self(
            $entry->date(),
            $input->moodRating(),
            $input->sleepQuality(),
            $input->events(),
            $input->thoughts(),
            $input->emotions(),
        );
    }

    public function date(): LocalDate
    {
        return $this->date;
    }

    public function moodRating(): int
    {
        return $this->moodRating;
    }

    public function sleepQuality(): ?int
    {
        return $this->sleepQuality;
    }

    public function events(): string
    {
        return $this->events;
    }

    public function thoughts(): string
    {
        return $this->thoughts;
    }

    public function emotions(): string
    {
        return $this->emotions;
    }
}
