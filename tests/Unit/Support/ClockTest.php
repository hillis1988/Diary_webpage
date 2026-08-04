<?php

declare(strict_types=1);

namespace Diary\Tests\Unit\Support;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use Diary\Support\FixedClock;
use Diary\Support\SystemClock;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Lockout windows, session idleness and date boundaries are all driven through
 * a Clock, so tests can generate elapsed time without sleeping. Both
 * implementations must report UTC.
 */
final class ClockTest extends TestCase
{
    public function testTheSystemClockReportsUtc(): void
    {
        $now = (new SystemClock())->now();

        self::assertSame('UTC', $now->getTimezone()->getName());
        self::assertLessThan(5, abs($now->getTimestamp() - time()), 'the system clock reads real time');
    }

    public function testAFixedClockStandsStillUntilAdvanced(): void
    {
        $clock = FixedClock::at('2024-02-29 12:00:00');

        self::assertSame('2024-02-29 12:00:00', $clock->now()->format('Y-m-d H:i:s'));
        self::assertSame('2024-02-29 12:00:00', $clock->now()->format('Y-m-d H:i:s'));
        self::assertSame('UTC', $clock->now()->getTimezone()->getName());
    }

    public function testAdvancingMovesTimeForwardAndBackward(): void
    {
        $clock = FixedClock::at('2024-02-28 23:59:00');

        $clock->advanceSeconds(60);
        self::assertSame('2024-02-29 00:00:00', $clock->now()->format('Y-m-d H:i:s'), 'into the leap day');

        $clock->advanceMinutes(15);
        self::assertSame('2024-02-29 00:15:00', $clock->now()->format('Y-m-d H:i:s'));

        $clock->advanceDays(1);
        self::assertSame('2024-03-01 00:15:00', $clock->now()->format('Y-m-d H:i:s'), 'off the leap day');

        $clock->advanceSeconds(-900);
        self::assertSame('2024-03-01 00:00:00', $clock->now()->format('Y-m-d H:i:s'));

        $clock->advance(new DateInterval('P1D'));
        self::assertSame('2024-03-02 00:00:00', $clock->now()->format('Y-m-d H:i:s'));
    }

    public function testSettingTheClockConvertsToUtc(): void
    {
        $clock = FixedClock::at('2024-01-01 00:00:00');
        $clock->set(new DateTimeImmutable('2024-06-01 12:00:00', new DateTimeZone('+02:00')));

        self::assertSame('2024-06-01 10:00:00', $clock->now()->format('Y-m-d H:i:s'));
        self::assertSame('UTC', $clock->now()->getTimezone()->getName());
    }

    public function testAnExpressionWithoutAZoneIsReadAsUtc(): void
    {
        self::assertSame(
            '2024-02-29 12:00:00',
            FixedClock::at('2024-02-29T12:00:00')->now()->format('Y-m-d H:i:s')
        );
    }

    public function testAnUnparseableExpressionIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        FixedClock::at('not a time');
    }
}
