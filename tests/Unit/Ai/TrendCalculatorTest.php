<?php

declare(strict_types=1);

namespace Diary\Tests\Unit\Ai;

use Diary\Access\OwnerId;
use Diary\Ai\TrendCalculator;
use Diary\Ai\TrendDirection;
use Diary\Diary\DiaryEntry;
use Diary\Diary\DiaryEntryInput;
use Diary\Support\LocalDate;
use Diary\Support\Ulid;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/**
 * TrendCalculator: deterministic count/mean/min/max for mood and sleep
 * series, tolerating missing sleep values, and a direction derived from a
 * least-squares slope (Requirement 9.2).
 */
final class TrendCalculatorTest extends TestCase
{
    private TrendCalculator $calculator;

    protected function setUp(): void
    {
        $this->calculator = new TrendCalculator();
    }

    public function testBasicMeanMinMaxCountForASimpleMoodSeries(): void
    {
        $entries = $this->entries([
            ['mood' => 4, 'sleep' => 3],
            ['mood' => 6, 'sleep' => 4],
            ['mood' => 8, 'sleep' => 2],
        ]);

        $metrics = $this->calculator->compute($entries);

        self::assertSame(3, $metrics->entryCount());
        self::assertSame(3, $metrics->mood()->count());
        self::assertEqualsWithDelta(6.0, $metrics->mood()->mean(), 0.0001);
        self::assertSame(4, $metrics->mood()->min());
        self::assertSame(8, $metrics->mood()->max());
    }

    public function testSleepSeriesExcludesEntriesWithNullSleepQuality(): void
    {
        $entries = $this->entries([
            ['mood' => 5, 'sleep' => 3],
            ['mood' => 5, 'sleep' => null],
            ['mood' => 5, 'sleep' => 5],
        ]);

        $metrics = $this->calculator->compute($entries);

        self::assertSame(3, $metrics->entryCount());
        self::assertSame(2, $metrics->sleep()->count());
        self::assertEqualsWithDelta(4.0, $metrics->sleep()->mean(), 0.0001);
        self::assertSame(3, $metrics->sleep()->min());
        self::assertSame(5, $metrics->sleep()->max());
    }

    public function testClearlyIncreasingMoodValuesYieldImprovingDirection(): void
    {
        $entries = $this->entries([
            ['mood' => 2, 'sleep' => null],
            ['mood' => 4, 'sleep' => null],
            ['mood' => 6, 'sleep' => null],
            ['mood' => 8, 'sleep' => null],
            ['mood' => 10, 'sleep' => null],
        ]);

        $metrics = $this->calculator->compute($entries);

        self::assertSame(TrendDirection::Improving, $metrics->mood()->direction());
    }

    public function testClearlyDecreasingMoodValuesYieldDecliningDirection(): void
    {
        $entries = $this->entries([
            ['mood' => 10, 'sleep' => null],
            ['mood' => 8, 'sleep' => null],
            ['mood' => 6, 'sleep' => null],
            ['mood' => 4, 'sleep' => null],
            ['mood' => 2, 'sleep' => null],
        ]);

        $metrics = $this->calculator->compute($entries);

        self::assertSame(TrendDirection::Declining, $metrics->mood()->direction());
    }

    public function testFlatNoisyMoodSeriesYieldsStableDirection(): void
    {
        $entries = $this->entries([
            ['mood' => 5, 'sleep' => null],
            ['mood' => 6, 'sleep' => null],
            ['mood' => 5, 'sleep' => null],
            ['mood' => 4, 'sleep' => null],
            ['mood' => 5, 'sleep' => null],
            ['mood' => 6, 'sleep' => null],
        ]);

        $metrics = $this->calculator->compute($entries);

        self::assertSame(TrendDirection::Stable, $metrics->mood()->direction());
    }

    public function testZeroEntriesYieldsSensibleDefaults(): void
    {
        $metrics = $this->calculator->compute([]);

        self::assertSame(0, $metrics->entryCount());

        self::assertSame(0, $metrics->mood()->count());
        self::assertNull($metrics->mood()->mean());
        self::assertNull($metrics->mood()->min());
        self::assertNull($metrics->mood()->max());
        self::assertSame(TrendDirection::Stable, $metrics->mood()->direction());

        self::assertSame(0, $metrics->sleep()->count());
        self::assertNull($metrics->sleep()->mean());
        self::assertNull($metrics->sleep()->min());
        self::assertNull($metrics->sleep()->max());
        self::assertSame(TrendDirection::Stable, $metrics->sleep()->direction());
    }

    public function testOneEntryYieldsStableDirectionWithCountOne(): void
    {
        $entries = $this->entries([
            ['mood' => 7, 'sleep' => 4],
        ]);

        $metrics = $this->calculator->compute($entries);

        self::assertSame(1, $metrics->entryCount());
        self::assertSame(1, $metrics->mood()->count());
        self::assertSame(TrendDirection::Stable, $metrics->mood()->direction());
        self::assertSame(1, $metrics->sleep()->count());
        self::assertSame(TrendDirection::Stable, $metrics->sleep()->direction());
    }

    /**
     * @param list<array{mood: int, sleep: ?int}> $rows
     * @return list<DiaryEntry>
     */
    private function entries(array $rows): array
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
}
