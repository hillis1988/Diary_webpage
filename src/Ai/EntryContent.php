<?php

declare(strict_types=1);

namespace Diary\Ai;

use Diary\Diary\DiaryEntryInput;

/**
 * The pseudonymised content of one Diary_Entry, exactly as handed to an LLM
 * provider.
 *
 * This is a deliberately narrow shape: it carries only the answers to the
 * structured question set. There is no field here for a name, an email
 * address, an account id, or a date - nothing that could identify the person
 * the entry belongs to (Requirement 6.1). {@see PromptBuilder} can only ever
 * label what this class exposes, so keeping identifiers off this object is
 * what keeps them out of the prompt, structurally rather than by convention.
 */
final class EntryContent
{
    public function __construct(
        private readonly int $moodRating,
        private readonly ?int $sleepQuality,
        private readonly string $events,
        private readonly string $thoughts,
        private readonly string $emotions,
    ) {
    }

    /**
     * Strip a validated {@see DiaryEntryInput} down to the pseudonymised
     * content a provider may see. The input's date is intentionally left
     * behind; it plays no part in the CBT prompt.
     */
    public static function fromInput(DiaryEntryInput $input): self
    {
        return new self(
            $input->moodRating(),
            $input->sleepQuality(),
            $input->events(),
            $input->thoughts(),
            $input->emotions(),
        );
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
