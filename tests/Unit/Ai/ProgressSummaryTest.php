<?php

declare(strict_types=1);

namespace Diary\Tests\Unit\Ai;

use Diary\Ai\CbtAdvice;
use Diary\Ai\ProgressSummary;
use Diary\Ai\SeriesStats;
use Diary\Ai\TrendDirection;
use Diary\Ai\TrendMetrics;
use PHPUnit\Framework\TestCase;

/**
 * ProgressSummary is a plain three-field carrier (Requirement 5.4): every
 * accessor returns exactly the value it was constructed with, including the
 * nested CbtAdvice and TrendMetrics objects by identity.
 */
final class ProgressSummaryTest extends TestCase
{
    public function testAccessorsReturnExactlyTheConstructedValues(): void
    {
        $narrative = 'Your mood has trended upward over the past month, with steadier sleep as well.';
        $advice = new CbtAdvice(
            'All-or-nothing thinking about missed workouts',
            'Black-and-white thinking, overgeneralization',
            'Missing one workout does not undo the whole month of progress',
            'Plan the next workout instead of dwelling on the missed one',
        );
        $stats = SeriesStats::of(3, 6.0, 5, 7, TrendDirection::Stable);
        $metrics = TrendMetrics::of(3, $stats, $stats);

        $summary = new ProgressSummary($narrative, $advice, $metrics);

        self::assertSame($narrative, $summary->narrative());
        self::assertSame($advice, $summary->advice());
        self::assertSame($metrics, $summary->metrics());
    }
}
