<?php

declare(strict_types=1);

namespace Diary\Ai;

use Diary\Support\LocalDate;

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
     * The fixed system instruction: timeline framing and chronological-order
     * reasoning, the strict-JSON two-top-level-field contract (`summary`
     * plus a four-field `advice` object), the multi-sentence requirements on
     * `summary`/`pattern`/`balanced_perspective`/`next_action`, the
     * distortions-or-explicit-none instruction, CBT grounding, and the
     * no-invented-statistics and no-identifying-information instructions.
     */
    public function systemPrompt(): string
    {
        return 'You are a CBT-informed wellbeing assistant writing a progress summary over a date range. '
            . 'You are given a JSON object containing a date range, computed trend metrics, and a timeline '
            . 'of diary entries and milestones ordered chronologically from earliest to latest date. Use '
            . 'that chronological order to assess whether the trends across the date range show '
            . 'improvement, decline, or stability - reason about the sequence, not isolated days. Respond '
            . 'with strict JSON only - no markdown, no commentary, no surrounding text - containing '
            . 'exactly two top-level fields: a string field "summary", and an object field "advice" '
            . 'containing exactly four string fields: "pattern", "distortions", "balanced_perspective", '
            . 'and "next_action". Do not add any other fields. Write "summary" as multiple sentences '
            . 'describing how the mood and sleep trends moved across the date range and how they relate '
            . 'to any milestones given; it must not be a single terse sentence. Write "pattern" as a '
            . 'short paragraph of multiple sentences describing the pattern or formulation you observe '
            . 'across the entries. Write "distortions" as a short paragraph naming any cognitive '
            . 'distortions you identify across the entries, or explicitly state that none were '
            . 'identified if none apply - never fabricate one where none fits. Write '
            . '"balanced_perspective" as a short paragraph offering a balanced, credible alternative '
            . 'perspective on the pattern described. Write "next_action" as a short paragraph describing '
            . 'one concrete action the person could take next. Ground "summary" and "advice" in '
            . 'Cognitive Behavioural Therapy: distinguish facts from interpretations, validate the '
            . 'person\'s emotion without automatically validating the interpretation producing it, favour '
            . 'credible balanced thinking over forced positivity, and do not diagnose any condition - '
            . 'not every difficulty is a thinking error, so do not force a reframe where none fits. Do '
            . 'not invent statistics beyond the trend metrics given in the JSON. Do not ask for, guess, '
            . 'or reference the person\'s name, email address, or any other identifying information; you '
            . 'are only ever given pseudonymised entry content, milestone content, and computed trend '
            . 'metrics for a date range.';
    }

    /**
     * Builds the structured payload sent to the LLM provider as the user
     * message content: the date range, computed trend metrics, and the
     * entries/milestones sorted ascending by date (Requirement 1.4).
     */
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
     * Stable ascending sort by date; PHP's usort() is stable since PHP 8.0,
     * so records sharing a date keep their relative input order.
     *
     * @template T
     * @param list<T> $records
     * @param callable(T): LocalDate $dateOf
     * @return list<T>
     */
    private static function sortedByDate(array $records, callable $dateOf): array
    {
        usort($records, static fn ($a, $b): int => $dateOf($a)->compareTo($dateOf($b)));

        return $records;
    }
}
