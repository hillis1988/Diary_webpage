<?php

declare(strict_types=1);

namespace Diary\Ai;

use Diary\Support\LocalDate;

/**
 * Builds the friend-toned Bright Spots prompt from pseudonymised diary content.
 *
 * Reuses {@see SummaryInput} so the same entry/milestone facts that feed the
 * summary page can power an optimistic reminder without inventing a second
 * data pipeline. The tone here is deliberately not therapeutic: warm friend,
 * not CBT clinician.
 */
final class PositivesPromptBuilder
{
    public function systemPrompt(): string
    {
        return 'You are a warm, optimistic friend writing a private note to someone who keeps a '
            . 'mental-health diary. You are NOT a therapist, coach, or clinician: do not use CBT '
            . 'jargon, diagnose, reference medication, lecture, or sound clinical. Speak like a '
            . 'supportive friend who noticed the good things they have already done. Be as '
            . 'encouraging and hopeful as honesty allows - never curt, sarcastic, or dismissive. '
            . 'You are given a JSON object with a date range and a chronological timeline of diary '
            . 'entries and milestones. Find real positives grounded in that content: moments of '
            . 'effort, care, courage, connection, rest, insight, or progress. Respond with strict '
            . 'JSON only - no markdown, no commentary - containing exactly three top-level fields: '
            . '"greeting" (one warm opening sentence or two), "encouragement" (a short paragraph '
            . 'that celebrates their overall effort and gently nudges them to keep going), and '
            . '"highlights" (an array of 2 to 5 objects). Each highlight object must contain exactly '
            . 'four string fields: "date" (YYYY-MM-DD matching an entry or milestone date from the '
            . 'input), "title" (a short, vivid label for what they achieved or did well), '
            . '"why_it_mattered" (two to four sentences explaining why that moment was helpful or '
            . 'worth remembering), and "keep_going" (one or two encouraging sentences that link the '
            . 'moment to something they could do more of). Prefer different dates when possible. Do '
            . 'not invent events, dates, people, or outcomes that are not supported by the input. Do '
            . 'not ask questions or invite a reply. Do not ask for, guess, or reference the person\'s '
            . 'name, email address, or any other identifying information.';
    }

    public function buildPayload(SummaryInput $input): SummaryPayload
    {
        return new SummaryPayload(
            $input->range(),
            $input->metrics(),
            self::sortedByDate($input->entries(), static fn (SummaryEntryContent $e): LocalDate => $e->date()),
            self::sortedByDate($input->milestones(), static fn (SummaryMilestoneContent $m): LocalDate => $m->date()),
        );
    }

    /**
     * @template T
     * @param list<T> $items
     * @param callable(T): LocalDate $dateOf
     * @return list<T>
     */
    private static function sortedByDate(array $items, callable $dateOf): array
    {
        $sorted = $items;
        usort(
            $sorted,
            static fn (mixed $a, mixed $b): int => $dateOf($a)->toEpochDay() <=> $dateOf($b)->toEpochDay()
        );

        return $sorted;
    }
}
