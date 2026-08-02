<?php

declare(strict_types=1);

namespace Diary\Ai;

use Diary\Diary\QuestionSet;

/**
 * Builds the pseudonymised prompt sent to the LLM provider for a progress
 * summary (Requirements 9.1-9.3).
 *
 * Mirrors {@see PromptBuilder}'s precedent: the prompt is built exclusively
 * from a {@see SummaryInput}, whose entries and milestones are already
 * pseudonymised content ({@see SummaryEntryContent}, {@see
 * SummaryMilestoneContent}) carrying no owner id or record id. This class
 * never receives an `OwnerId`, a raw `DiaryEntry` or a raw `Milestone`; that
 * narrowing at the type level is what keeps the prompt pseudonymised.
 *
 * The trend metrics are computed deterministically by {@see TrendCalculator}
 * and handed to the provider as facts to narrate (design.md), never
 * recomputed by the model itself.
 */
final class SummaryPromptBuilder
{
    /**
     * The fixed system instruction: strict JSON with exactly one field,
     * asking the model to relate trends to any milestones supplied.
     */
    public function systemPrompt(): string
    {
        return 'You are a CBT-informed wellbeing assistant writing a progress summary over a date range. '
            . 'Respond with strict JSON only - no markdown, no commentary, no surrounding text - '
            . 'containing exactly one string field: "narrative" (a short progress summary that '
            . 'discusses the mood and sleep trend metrics provided and relates them to any '
            . 'milestones provided). Do not add any other fields. Do not invent statistics beyond '
            . 'the metrics given. Do not ask for, guess, or reference the person\'s name, email '
            . 'address, or any other identifying information; you are only ever given pseudonymised '
            . 'entry content, milestone content, and computed trend metrics for a date range.';
    }

    /**
     * The pseudonymised summary input: the date range, computed trend
     * metrics, entry content, and milestone content within that range.
     */
    public function userPrompt(SummaryInput $input): string
    {
        $lines = [];

        $range = $input->range();
        $lines[] = sprintf('Date range: %s to %s', $range->start()->toIso(), $range->end()->toIso());
        $lines[] = '';
        $lines[] = 'Trend metrics:';
        $lines[] = $this->seriesLine('Mood rating', $input->metrics()->mood(), QuestionSet::moodRating()->scaleDescription());
        $lines[] = $this->seriesLine('Sleep quality', $input->metrics()->sleep(), QuestionSet::sleepQuality()->scaleDescription());

        $lines[] = '';
        $lines[] = 'Diary entries:';
        foreach ($input->entries() as $entry) {
            $lines[] = $this->entryLine($entry);
        }

        $lines[] = '';
        $lines[] = 'Milestones:';
        if ($input->milestones() === []) {
            $lines[] = 'None in this date range.';
        } else {
            foreach ($input->milestones() as $milestone) {
                $lines[] = $this->milestoneLine($milestone);
            }
        }

        return implode("\n", $lines);
    }

    private function seriesLine(string $label, SeriesStats $stats, string $scaleDescription): string
    {
        if ($stats->count() === 0) {
            return sprintf('%s: no data (scale: %s)', $label, $scaleDescription);
        }

        return sprintf(
            '%s: count %d, mean %.2f, min %d, max %d, direction %s (scale: %s)',
            $label,
            $stats->count(),
            $stats->mean(),
            $stats->min(),
            $stats->max(),
            $stats->direction()->value,
            $scaleDescription,
        );
    }

    private function entryLine(SummaryEntryContent $entry): string
    {
        $sleep = $entry->sleepQuality() === null ? 'n/a' : (string) $entry->sleepQuality();

        return sprintf(
            '- %s: mood %d, sleep %s. Events: %s Thoughts: %s Emotions: %s',
            $entry->date()->toIso(),
            $entry->moodRating(),
            $sleep,
            $entry->events(),
            $entry->thoughts(),
            $entry->emotions(),
        );
    }

    private function milestoneLine(SummaryMilestoneContent $milestone): string
    {
        return sprintf(
            '- %s (%s): %s',
            $milestone->date()->toIso(),
            $milestone->category()->value,
            $milestone->description(),
        );
    }
}
