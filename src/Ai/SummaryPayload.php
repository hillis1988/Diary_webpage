<?php

declare(strict_types=1);

namespace Diary\Ai;

use Diary\Diary\QuestionSet;
use Diary\Support\DateRange;
use JsonSerializable;

/**
 * The entire structured payload sent to a {@see SummaryProvider} as the
 * user message content: the date range being summarised, the computed
 * {@see TrendMetrics} for mood and sleep, and the date-ordered pseudonymised
 * entry and milestone records for that range (Requirements 1.1, 1.5, 1.6).
 *
 * Deliberately a plain, immutable, JsonSerializable value object with no
 * behaviour beyond exposing its own shape - Requirement 10's closed-shape,
 * no-identifiers guarantee is checkable by reading jsonSerialize() alone.
 */
final class SummaryPayload implements JsonSerializable
{
    /**
     * @param list<SummaryEntryContent> $entries date-ordered
     * @param list<SummaryMilestoneContent> $milestones date-ordered
     */
    public function __construct(
        private readonly DateRange $range,
        private readonly TrendMetrics $metrics,
        private readonly array $entries,
        private readonly array $milestones,
    ) {
    }

    /**
     * @return array{
     *     date_range: array{start: string, end: string},
     *     trend_metrics: array{mood: array<string, mixed>, sleep: array<string, mixed>},
     *     entries: list<SummaryEntryContent>,
     *     milestones: list<SummaryMilestoneContent>
     * }
     */
    public function jsonSerialize(): array
    {
        return [
            'date_range' => [
                'start' => $this->range->start()->toIso(),
                'end' => $this->range->end()->toIso(),
            ],
            'trend_metrics' => [
                'mood' => self::seriesJson($this->metrics->mood(), QuestionSet::MOOD_MIN, QuestionSet::MOOD_MAX),
                'sleep' => self::seriesJson($this->metrics->sleep(), QuestionSet::SLEEP_MIN, QuestionSet::SLEEP_MAX),
            ],
            'entries' => $this->entries,
            'milestones' => $this->milestones,
        ];
    }

    /**
     * @return array{count: int, mean: ?float, min: ?int, max: ?int, direction: string, scale: array{min: int, max: int}}
     */
    private static function seriesJson(SeriesStats $series, int $scaleMin, int $scaleMax): array
    {
        $hasData = $series->count() !== 0;

        return [
            'count' => $series->count(),
            'mean' => $hasData ? $series->mean() : null,
            'min' => $hasData ? $series->min() : null,
            'max' => $hasData ? $series->max() : null,
            'direction' => $series->direction()->value,
            'scale' => [
                'min' => $scaleMin,
                'max' => $scaleMax,
            ],
        ];
    }
}
