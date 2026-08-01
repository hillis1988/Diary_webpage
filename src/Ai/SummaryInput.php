<?php

declare(strict_types=1);

namespace Diary\Ai;

use Diary\Support\DateRange;

/**
 * `SummaryInput { range, metrics, entries[], milestones[] }` (design.md),
 * exactly what {@see AiSummaryService::summarise()} hands a
 * {@see SummaryProvider} to narrate (Requirement 9.1).
 *
 * `entries` and `milestones` carry pseudonymised content only -
 * {@see SummaryEntryContent} and {@see SummaryMilestoneContent}, never a raw
 * `DiaryEntry` or `Milestone` domain object carrying an `OwnerId` - mirroring
 * the same privacy precedent {@see EntryContent} already established for
 * AI_Feedback_Service (Requirement 6.1). `metrics` is computed in code by
 * {@see TrendCalculator}, not by the LLM, so the provider is only ever asked
 * to narrate facts it is given, never to compute them itself.
 */
final class SummaryInput
{
    /**
     * @param list<SummaryEntryContent> $entries in chronological order
     * @param list<SummaryMilestoneContent> $milestones in chronological order
     */
    public function __construct(
        private readonly DateRange $range,
        private readonly TrendMetrics $metrics,
        private readonly array $entries,
        private readonly array $milestones,
    ) {
    }

    public function range(): DateRange
    {
        return $this->range;
    }

    public function metrics(): TrendMetrics
    {
        return $this->metrics;
    }

    /**
     * @return list<SummaryEntryContent>
     */
    public function entries(): array
    {
        return $this->entries;
    }

    /**
     * @return list<SummaryMilestoneContent>
     */
    public function milestones(): array
    {
        return $this->milestones;
    }
}
