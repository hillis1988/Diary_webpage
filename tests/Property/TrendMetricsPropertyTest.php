<?php

declare(strict_types=1);

namespace Diary\Tests\Property;

use Diary\Access\OwnerId;
use Diary\Ai\TrendCalculator;
use Diary\Ai\TrendDirection;
use Diary\Diary\DiaryEntry;
use Diary\Diary\DiaryEntryInput;
use Diary\Diary\QuestionSet;
use Diary\Support\LocalDate;
use Diary\Support\Ulid;
use DateTimeImmutable;
use Eris\Generator;
use Eris\TestTrait;
use PHPUnit\Framework\TestCase;

/**
 * Property 5: Trend metrics equal a reference computation.
 *
 * For every generated series of Diary_Entry records - including series where
 * some or all sleep-quality answers are missing - {@see TrendCalculator}'s
 * count, mean, minimum, maximum and direction for the mood series and the
 * sleep series are compared against a reference computed independently in
 * this test: a plain sum/count mean, `min()`/`max()`, and a least-squares
 * slope computed via the sum-based normal-equations formula rather than
 * TrendCalculator's mean-centred one. Agreeing on every generated series,
 * including empty ones and series with every sleep answer missing, is what
 * pins down "no off-by-one in the count, no wrong divisor in the mean, no
 * excluded or double-counted value at the range boundary" (design.md,
 * Property 5).
 *
 * The stable/improving/declining threshold - 5% of the question's scale
 * range per entry-step - is a documented judgement call on
 * {@see TrendCalculator}, not something Requirement 9.2 fixes independently,
 * so the reference direction in this test applies that same documented
 * percentage to its own independently computed slope, rather than reading
 * TrendCalculator's internals.
 *
 * "...and both metric sets appear in the rendered summary" (design.md,
 * Property 5) is the AI_Summary_Service's rendering, which task 14.3 has not
 * yet built; that half of the property is out of scope for this test, the
 * same way Property 4's recommendation-retrieval half was deferred in
 * OneEntryPerDatePropertyTest until the collaborator it depends on existed.
 *
 * Requirements: 9.2.
 */
final class TrendMetricsPropertyTest extends TestCase
{
    use TestTrait;

    /**
     * TrendCalculator::STABLE_THRESHOLD_FRACTION, documented on that class as
     * "5% of the scale range per entry-step" - a specified judgement call,
     * not a hidden implementation detail.
     */
    private const STABLE_THRESHOLD_FRACTION = 0.05;

    private const MOOD_RANGE = QuestionSet::MOOD_MAX - QuestionSet::MOOD_MIN;
    private const SLEEP_RANGE = QuestionSet::SLEEP_MAX - QuestionSet::SLEEP_MIN;

    private TrendCalculator $calculator;

    protected function setUp(): void
    {
        $this->calculator = new TrendCalculator();
    }

    // Feature: mental-health-diary, Property 5: Trend metrics equal a reference computation
    public function testTrendMetricsEqualAReferenceComputation(): void
    {
        $this->limitTo(100)
            ->forAll(self::entrySeries())
            ->then(function (array $rows): void {
                $entries = self::toDiaryEntries($rows);
                $metrics = $this->calculator->compute($entries);

                self::assertSame(count($rows), $metrics->entryCount());

                $moodValues = array_map(static fn (array $row): int => $row['mood'], $rows);
                $sleepValues = array_values(array_filter(
                    array_map(static fn (array $row): ?int => $row['sleep'], $rows),
                    static fn (?int $value): bool => $value !== null
                ));

                self::assertSeriesMatchesReference($metrics->mood(), $moodValues, self::MOOD_RANGE, 'mood');
                self::assertSeriesMatchesReference($metrics->sleep(), $sleepValues, self::SLEEP_RANGE, 'sleep');
            });
    }

    /**
     * @param list<int> $values chronological values the series was built from
     */
    private static function assertSeriesMatchesReference(
        \Diary\Ai\SeriesStats $stats,
        array $values,
        int $scaleRange,
        string $label
    ): void {
        $reference = self::referenceStats($values, $scaleRange);

        self::assertSame($reference['count'], $stats->count(), "{$label}: count differs from the reference");

        if ($reference['count'] === 0) {
            self::assertNull($stats->mean(), "{$label}: an empty series must have no mean");
            self::assertNull($stats->min(), "{$label}: an empty series must have no minimum");
            self::assertNull($stats->max(), "{$label}: an empty series must have no maximum");
        } else {
            self::assertEqualsWithDelta(
                $reference['mean'],
                $stats->mean(),
                0.0000001,
                "{$label}: mean differs from the reference"
            );
            self::assertSame($reference['min'], $stats->min(), "{$label}: minimum differs from the reference");
            self::assertSame($reference['max'], $stats->max(), "{$label}: maximum differs from the reference");
        }

        self::assertSame(
            $reference['direction'],
            $stats->direction(),
            "{$label}: direction differs from the reference"
        );
    }

    /**
     * The reference computation, written fresh and independently of
     * TrendCalculator: a plain sum/count mean, PHP's own min()/max(), and a
     * least-squares slope via the sum-based normal-equations formula
     *   slope = (n*Sum(xy) - Sum(x)*Sum(y)) / (n*Sum(x^2) - Sum(x)^2)
     * against x = 0, 1, 2, ... rather than TrendCalculator's mean-centred
     * formula.
     *
     * @param list<int> $values in chronological order
     *
     * @return array{count: int, mean: ?float, min: ?int, max: ?int, direction: TrendDirection}
     */
    private static function referenceStats(array $values, int $scaleRange): array
    {
        $count = count($values);

        if ($count === 0) {
            return ['count' => 0, 'mean' => null, 'min' => null, 'max' => null, 'direction' => TrendDirection::Stable];
        }

        $mean = array_sum($values) / $count;
        $min = min($values);
        $max = max($values);

        if ($count < 2) {
            return ['count' => $count, 'mean' => $mean, 'min' => $min, 'max' => $max, 'direction' => TrendDirection::Stable];
        }

        $n = $count;
        $sumX = 0.0;
        $sumY = 0.0;
        $sumXY = 0.0;
        $sumX2 = 0.0;

        foreach ($values as $x => $y) {
            $sumX += $x;
            $sumY += $y;
            $sumXY += $x * $y;
            $sumX2 += $x * $x;
        }

        $denominator = $n * $sumX2 - $sumX * $sumX;
        $slope = $denominator === 0.0 ? 0.0 : ($n * $sumXY - $sumX * $sumY) / $denominator;
        $threshold = self::STABLE_THRESHOLD_FRACTION * $scaleRange;

        $direction = match (true) {
            $slope > $threshold => TrendDirection::Improving,
            $slope < -$threshold => TrendDirection::Declining,
            default => TrendDirection::Stable,
        };

        return ['count' => $count, 'mean' => $mean, 'min' => $min, 'max' => $max, 'direction' => $direction];
    }

    /**
     * @param list<array{mood: int, sleep: ?int}> $rows
     *
     * @return list<DiaryEntry>
     */
    private static function toDiaryEntries(array $rows): array
    {
        $owner = OwnerId::fromString(Ulid::generate());
        $now = new DateTimeImmutable('2024-01-01T00:00:00Z');
        $date = LocalDate::of(2024, 1, 1);

        $entries = [];

        foreach ($rows as $i => $row) {
            $input = DiaryEntryInput::of(
                date: $date->plusDays($i),
                moodRating: $row['mood'],
                sleepQuality: $row['sleep'],
            );

            $entries[] = DiaryEntry::of(Ulid::generate(), $owner, $input, $now, $now);
        }

        return $entries;
    }

    /**
     * Zero to twenty entries, each with a mood rating on the 1-10 scale
     * (Requirement 9.2's required question) and a sleep quality on the 1-5
     * scale or missing (Requirement 9.2's "series with missing sleep-quality
     * values"), in the chronological order TrendCalculator assumes.
     */
    private static function entrySeries(): \Eris\Generator
    {
        return Generator\bind(
            Generator\choose(0, 20),
            static fn (int $length): \Eris\Generator => $length === 0
                ? Generator\constant([])
                : Generator\vector($length, self::entryRow())
        );
    }

    /**
     * @return \Eris\Generator array{mood: int, sleep: ?int}
     */
    private static function entryRow(): \Eris\Generator
    {
        return Generator\map(
            static fn (array $parts): array => ['mood' => $parts[0], 'sleep' => $parts[1]],
            Generator\tuple(
                Generator\choose(QuestionSet::MOOD_MIN, QuestionSet::MOOD_MAX),
                Generator\oneOf(
                    Generator\constant(null),
                    Generator\choose(QuestionSet::SLEEP_MIN, QuestionSet::SLEEP_MAX)
                )
            )
        );
    }
}
