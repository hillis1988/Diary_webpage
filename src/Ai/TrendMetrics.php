<?php

declare(strict_types=1);

namespace Diary\Ai;

/**
 * The output of {@see TrendCalculator}: how many entries the metrics were
 * computed from, plus mood and sleep series statistics (Requirement 9.2).
 * Handed to the AI_Summary_Service's provider as facts to narrate, never
 * computed by the LLM itself.
 */
final class TrendMetrics
{
    private function __construct(
        private readonly int $entryCount,
        private readonly SeriesStats $mood,
        private readonly SeriesStats $sleep,
    ) {
    }

    public static function of(int $entryCount, SeriesStats $mood, SeriesStats $sleep): self
    {
        return new self($entryCount, $mood, $sleep);
    }

    /** How many Diary_Entry records the metrics were computed from. */
    public function entryCount(): int
    {
        return $this->entryCount;
    }

    public function mood(): SeriesStats
    {
        return $this->mood;
    }

    /** Built only from entries whose sleep quality question was answered. */
    public function sleep(): SeriesStats
    {
        return $this->sleep;
    }
}
