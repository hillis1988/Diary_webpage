<?php

declare(strict_types=1);

namespace Diary\Ai;

use Diary\Access\OwnerId;
use Diary\Diary\DiaryEntry;
use Diary\Diary\DiaryService;
use Diary\Milestone\Milestone;
use Diary\Milestone\MilestoneService;
use Diary\Support\DateRange;

/**
 * AI_Summary_Service (Requirements 9.1-9.5).
 *
 * Takes an {@see OwnerId} directly rather than a `SecurityContext`, the same
 * deviation from design.md's raw pseudocode already established by
 * {@see DiaryService} and {@see MilestoneService}: the summary page and
 * controller (a later task) own resolving the data owner and the
 * `authorise()` check via `AccessControlService::resolveDataOwner()` before
 * calling this, keeping this service free of anything HTTP-shaped.
 *
 * `summarise()`:
 *
 *   1. gathers exactly the owner's Diary_Entry and Milestone records whose
 *      dates fall within the inclusive selected range, via
 *      {@see DiaryService::entriesInRange()} and
 *      {@see MilestoneService::inRange()} (Requirements 9.1, 9.3);
 *   2. checks the entry count first (design.md, "Outcome precedence is
 *      explicit"): fewer than three entries always yields
 *      {@see SummaryOutcome::insufficientData()} without calling the
 *      provider at all, even if the provider would also have failed
 *      (Requirement 9.4);
 *   3. with three or more entries, computes {@see TrendMetrics} via
 *      {@see TrendCalculator} and builds a {@see SummaryInput} relating
 *      milestones to the range, then calls the {@see SummaryProvider};
 *   4. on a {@see ProviderError}, returns
 *      {@see SummaryOutcome::unavailable()} with the already-computed metrics
 *      so charts can still render (Requirement 9.5's unavailable notice remains);
 *      on success, returns {@see SummaryOutcome::summary()} carrying the
 *      narrative and the computed metrics.
 */
final class AiSummaryService
{
    /**
     * Fewer than this many Diary_Entry records in the selected range always
     * yields InsufficientData, before the provider is ever called
     * (Requirement 9.4).
     */
    private const MINIMUM_ENTRIES = 3;

    public function __construct(
        private readonly DiaryService $diaryService,
        private readonly MilestoneService $milestoneService,
        private readonly SummaryProvider $provider,
        private readonly TrendCalculator $trendCalculator = new TrendCalculator(),
    ) {
    }

    public function summarise(OwnerId $owner, DateRange $range): SummaryOutcome
    {
        $entries = $this->diaryService->entriesInRange($owner, $range);

        if (count($entries) < self::MINIMUM_ENTRIES) {
            return SummaryOutcome::insufficientData();
        }

        $milestones = $this->milestoneService->inRange($owner, $range);
        $metrics = $this->trendCalculator->compute($entries);
        $input = new SummaryInput(
            $range,
            $metrics,
            array_map(static fn (DiaryEntry $entry): SummaryEntryContent => SummaryEntryContent::fromEntry($entry), $entries),
            array_map(static fn (Milestone $milestone): SummaryMilestoneContent => SummaryMilestoneContent::fromMilestone($milestone), $milestones),
        );

        try {
            $summary = $this->provider->generate($input);
        } catch (ProviderError) {
            return SummaryOutcome::unavailable(SummaryOutcome::UNAVAILABLE_MESSAGE, $metrics);
        }

        return SummaryOutcome::summary($summary);
    }
}
