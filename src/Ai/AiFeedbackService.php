<?php

declare(strict_types=1);

namespace Diary\Ai;

use Diary\Diary\DiaryEntry;
use Diary\Support\Clock;

/**
 * AI_Feedback_Service (Requirements 6.1-6.5).
 *
 * Called after `Diary_Service::submitEntry()` has already committed the
 * entry, outside that transaction: nothing this class does can roll the
 * entry back, and every failure path here ends in {@see FeedbackOutcome::unavailable()}
 * rather than an exception escaping to the caller.
 *
 * `generateForEntry()`:
 *
 *   1. asks the {@see FeedbackProvider} for a recommendation from the entry's
 *      pseudonymised content;
 *   2. accepts it only when it carries exactly one non-empty positive focus
 *      and one non-empty suggested change (Requirements 6.2, 6.3) - the
 *      provider already guarantees both are present strings, this is the
 *      "and non-empty" half of shape validation;
 *   3. on acceptance, persists it encrypted with `provider`, `model`,
 *      `attempt_count` and `generated_at` (Requirement 6.4, one row per
 *      entry via {@see CbtRecommendationRepository}'s upsert);
 *   4. on a {@see ProviderError} or a shape-validation failure, persists
 *      `status = 'failed'` with an incremented `attempt_count` and returns
 *      `Unavailable` (Requirement 6.5).
 *
 * `retry()` is the retry control named in the design: it re-invokes the
 * provider through the same `generateForEntry()` path, so a retry updates
 * the same row rather than adding a second one.
 */
final class AiFeedbackService
{
    public function __construct(
        private readonly FeedbackProvider $provider,
        private readonly CbtRecommendationRepository $repository,
        private readonly AiConfig $config,
    ) {
    }

    public function generateForEntry(DiaryEntry $entry, Clock $clock): FeedbackOutcome
    {
        try {
            $recommendation = $this->provider->generate(EntryContent::fromInput($entry->input()));
        } catch (ProviderError) {
            $this->repository->recordFailure($entry->id(), $this->config->provider(), $this->config->model(), $clock);

            return FeedbackOutcome::unavailable();
        }

        if (!self::isNonEmpty($recommendation->positiveFocus()) || !self::isNonEmpty($recommendation->suggestedChange())) {
            $this->repository->recordFailure($entry->id(), $this->config->provider(), $this->config->model(), $clock);

            return FeedbackOutcome::unavailable();
        }

        $record = $this->repository->recordSuccess(
            $entry->id(),
            $recommendation,
            $this->config->provider(),
            $this->config->model(),
            $clock->now(),
            $clock,
        );

        return FeedbackOutcome::generated($record->recommendation());
    }

    /**
     * The retry control: re-invokes the provider for the same entry.
     * Upsert semantics in {@see CbtRecommendationRepository} keep this on the
     * one row `Requirement 6.4` allows, incrementing `attempt_count` again
     * rather than adding a second recommendation.
     */
    public function retry(DiaryEntry $entry, Clock $clock): FeedbackOutcome
    {
        return $this->generateForEntry($entry, $clock);
    }

    private static function isNonEmpty(string $value): bool
    {
        return trim($value) !== '';
    }
}
