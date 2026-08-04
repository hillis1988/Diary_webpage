<?php

declare(strict_types=1);

namespace Diary\Ai;

use Diary\Diary\DiaryEntry;
use Diary\Diary\QuestionSet;

/**
 * Deterministic trend metrics for mood rating and sleep quality across a set
 * of Diary_Entry records (Requirement 9.2): count, mean, minimum, maximum,
 * and a direction derived from a least-squares slope over the series.
 *
 * Computed in code, not by the LLM (design.md, "Trend metrics are computed
 * in code, not by the LLM"), so the numbers are deterministic and
 * unit-testable; AI_Summary_Service (task 14.3) hands them to the provider
 * as facts to narrate rather than letting the model invent statistics.
 *
 * **Chronological order is assumed, not enforced.** The x-coordinate for the
 * least-squares slope is each value's position in the input list (0, 1, 2,
 * ...), so the caller must supply entries in chronological (entry_date ASC)
 * order for the slope to mean anything. `DiaryEntryRepository::findInRange`
 * already returns entries in that order, matching how AI_Summary_Service (the
 * intended caller) will supply them, so this class does not re-sort.
 */
final class TrendCalculator
{
    /**
     * A slope threshold below which a series counts as Stable rather than
     * Improving or Declining, expressed as a fraction of the question's
     * scale range (max - min). Neither requirements.md nor design.md fixes
     * an exact number; 5% of the scale range per entry-step is used as a
     * judgment call for what counts as a "meaningfully changing" trend -
     * small enough to catch a steady drift, large enough that ordinary
     * day-to-day noise around a flat average does not get misread as a
     * trend.
     */
    private const STABLE_THRESHOLD_FRACTION = 0.05;

    /**
     * @param list<DiaryEntry> $entries in chronological order
     */
    public function compute(array $entries): TrendMetrics
    {
        $moodValues = array_map(
            static fn (DiaryEntry $entry): int => $entry->input()->moodRating(),
            $entries
        );

        $moodPoints = array_map(
            static fn (DiaryEntry $entry): TrendPoint => new TrendPoint(
                $entry->input()->date(),
                $entry->input()->moodRating()
            ),
            $entries
        );

        // Requirement 9.2: sleep quality is optional, so a missing answer is
        // excluded from the sleep series entirely rather than treated as zero.
        $sleepValues = array_values(array_filter(
            array_map(
                static fn (DiaryEntry $entry): ?int => $entry->input()->sleepQuality(),
                $entries
            ),
            static fn (?int $value): bool => $value !== null
        ));

        // Mirrors the exclusion above: a sleep point is built only for
        // entries whose sleep quality question was answered.
        $sleepPoints = array_values(array_filter(
            array_map(
                static fn (DiaryEntry $entry): ?TrendPoint => $entry->input()->sleepQuality() === null
                    ? null
                    : new TrendPoint($entry->input()->date(), $entry->input()->sleepQuality()),
                $entries
            ),
            static fn (?TrendPoint $point): bool => $point !== null
        ));

        $moodRange = QuestionSet::MOOD_MAX - QuestionSet::MOOD_MIN;
        $sleepRange = QuestionSet::SLEEP_MAX - QuestionSet::SLEEP_MIN;

        return TrendMetrics::of(
            entryCount: count($entries),
            mood: $this->seriesStats($moodValues, $moodRange, $moodPoints),
            sleep: $this->seriesStats($sleepValues, $sleepRange, $sleepPoints),
        );
    }

    /**
     * @param list<int> $values in chronological order
     * @param list<TrendPoint> $points in the same chronological order as $values
     */
    private function seriesStats(array $values, int $scaleRange, array $points): SeriesStats
    {
        $count = count($values);

        if ($count === 0) {
            // No data points: nothing to average or bound, and no slope to
            // read a direction from. Stable is the safe default (documented
            // on SeriesStats) rather than guessing Improving or Declining.
            return SeriesStats::of($count, null, null, null, TrendDirection::Stable, $points);
        }

        $mean = array_sum($values) / $count;
        $min = min($values);
        $max = max($values);

        return SeriesStats::of($count, $mean, $min, $max, $this->direction($values, $scaleRange), $points);
    }

    /**
     * @param list<int> $values in chronological order, at least one value
     */
    private function direction(array $values, int $scaleRange): TrendDirection
    {
        $count = count($values);

        if ($count < 2) {
            // A single point has no slope to speak of.
            return TrendDirection::Stable;
        }

        $slope = $this->leastSquaresSlope($values);
        $threshold = self::STABLE_THRESHOLD_FRACTION * $scaleRange;

        if ($slope > $threshold) {
            return TrendDirection::Improving;
        }

        if ($slope < -$threshold) {
            return TrendDirection::Declining;
        }

        return TrendDirection::Stable;
    }

    /**
     * The slope of the least-squares regression line for y = values[i]
     * against x = i (0, 1, 2, ...), i.e. change in value per chronological
     * step.
     *
     * @param list<int> $values at least two values
     */
    private function leastSquaresSlope(array $values): float
    {
        $n = count($values);
        $xMean = ($n - 1) / 2.0;
        $yMean = array_sum($values) / $n;

        $numerator = 0.0;
        $denominator = 0.0;

        foreach ($values as $x => $y) {
            $dx = $x - $xMean;
            $numerator += $dx * ($y - $yMean);
            $denominator += $dx * $dx;
        }

        if ($denominator === 0.0) {
            // All x values identical only happens when n < 2, already
            // handled by the caller, but guard against division by zero.
            return 0.0;
        }

        return $numerator / $denominator;
    }
}
