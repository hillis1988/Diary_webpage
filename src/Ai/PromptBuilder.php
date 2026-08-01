<?php

declare(strict_types=1);

namespace Diary\Ai;

use Diary\Diary\QuestionSet;

/**
 * Builds the pseudonymised prompt sent to the LLM provider for CBT-style
 * feedback on one Diary_Entry (Requirement 6.1).
 *
 * The prompt is built exclusively from an {@see EntryContent}, which itself
 * carries no name, email address, account id, or any other identifier - so
 * this class has nothing to leak even by mistake. It never receives an
 * `OwnerId`, a `UserId`, or a raw `DiaryEntry`; that narrowing at the type
 * level is what keeps the prompt pseudonymised rather than relying on this
 * class remembering to omit fields.
 */
final class PromptBuilder
{
    /**
     * The fixed system instruction: the response contract every provider call
     * relies on, asking for strict JSON with exactly the two expected fields.
     */
    public function systemPrompt(): string
    {
        return 'You are a CBT-informed wellbeing assistant reviewing one day\'s diary entry. '
            . 'Respond with strict JSON only - no markdown, no commentary, no surrounding text - '
            . 'containing exactly two string fields: "positive_focus" (one positive thing the '
            . 'person can focus on today) and "suggested_change" (one small, meaningful change '
            . 'they could try). Do not add any other fields. Do not ask for, guess, or reference '
            . 'the person\'s name, email address, or any other identifying information; you are '
            . 'only ever given the content of a single entry.';
    }

    /**
     * The pseudonymised entry content, labelled with the same question
     * prompts and scale descriptions the diary form itself uses, so the
     * question set stays the single source of truth (Requirement 5.1).
     */
    public function userPrompt(EntryContent $content): string
    {
        $lines = [];

        $moodQuestion = QuestionSet::moodRating();
        $lines[] = sprintf(
            '%s: %d out of %d (scale: %s)',
            $moodQuestion->prompt(),
            $content->moodRating(),
            $moodQuestion->scaleMax(),
            $moodQuestion->scaleDescription(),
        );

        if ($content->sleepQuality() !== null) {
            $sleepQuestion = QuestionSet::sleepQuality();
            $lines[] = sprintf(
                '%s: %d out of %d (scale: %s)',
                $sleepQuestion->prompt(),
                $content->sleepQuality(),
                $sleepQuestion->scaleMax(),
                $sleepQuestion->scaleDescription(),
            );
        }

        if ($content->events() !== '') {
            $lines[] = sprintf('%s %s', QuestionSet::byField(QuestionSet::EVENTS)->prompt(), $content->events());
        }

        if ($content->thoughts() !== '') {
            $lines[] = sprintf('%s %s', QuestionSet::byField(QuestionSet::THOUGHTS)->prompt(), $content->thoughts());
        }

        if ($content->emotions() !== '') {
            $lines[] = sprintf('%s %s', QuestionSet::byField(QuestionSet::EMOTIONS)->prompt(), $content->emotions());
        }

        return implode("\n", $lines);
    }
}
