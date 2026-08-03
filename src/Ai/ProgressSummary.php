<?php

declare(strict_types=1);

namespace Diary\Ai;

/**
 * `Summary(text, metrics)` (design.md): what a {@see SummaryProvider}
 * returns on success, and what {@see SummaryOutcome::summary()} carries
 * forward for rendering by the summary page (Requirements 9.1, 9.2).
 *
 * `metrics` is the same {@see TrendMetrics} handed to the provider as facts
 * to narrate, not anything the provider computed itself, so the numbers a
 * page renders always match {@see TrendCalculator}'s deterministic output.
 */
final class ProgressSummary
{
    public function __construct(
        private readonly string $narrative,
        private readonly CbtAdvice $advice,
        private readonly TrendMetrics $metrics,
    ) {
    }

    public function narrative(): string
    {
        return $this->narrative;
    }

    public function advice(): CbtAdvice
    {
        return $this->advice;
    }

    public function metrics(): TrendMetrics
    {
        return $this->metrics;
    }
}
