<?php

declare(strict_types=1);

namespace Diary\Ai;

/**
 * Adapter over the LLM HTTPS API that turns one Diary_Entry's pseudonymised
 * content into a CBT_Recommendation (Requirement 6.1).
 *
 * {@see HttpsFeedbackProvider} is the only production implementation; tests
 * substitute a fake so AI_Feedback_Service's own tests never make a network
 * call.
 */
interface FeedbackProvider
{
    /**
     * @throws ProviderError on any failure to obtain a recommendation: the
     *                       feature being disabled by configuration, a timeout
     *                       after the retry is exhausted, a non-2xx response,
     *                       or a response that is not valid strict JSON
     */
    public function generate(EntryContent $content): CbtRecommendation;
}
