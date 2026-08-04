<?php

declare(strict_types=1);

namespace Diary\Ai;

/**
 * Adapter over the LLM HTTPS API that turns a {@see SummaryInput} - a date
 * range, deterministic trend metrics, and pseudonymised entry and milestone
 * content - into a {@see ProgressSummary} narrative (Requirements 9.1-9.3).
 *
 * {@see HttpsSummaryProvider} is the only production implementation; tests
 * substitute a fake so AI_Summary_Service's own tests never make a network
 * call.
 */
interface SummaryProvider
{
    /**
     * @throws ProviderError on any failure to obtain a summary: the feature
     *                       being disabled by configuration, a timeout after
     *                       the retry is exhausted, a non-2xx response, or a
     *                       response that is not valid strict JSON
     */
    public function generate(SummaryInput $input): ProgressSummary;
}
