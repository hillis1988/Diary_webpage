<?php

declare(strict_types=1);

namespace Diary\Tests\Unit\Ai;

use Diary\Ai\TrendPoint;
use Diary\Support\LocalDate;
use PHPUnit\Framework\TestCase;

/**
 * TrendPoint is a plain, immutable two-field carrier: date() and value()
 * return exactly what the object was constructed with.
 */
final class TrendPointTest extends TestCase
{
    public function testAccessorsReturnExactlyTheConstructedValues(): void
    {
        $date = LocalDate::of(2025, 3, 14);
        $point = new TrendPoint($date, 7);

        self::assertSame($date, $point->date());
        self::assertSame(7, $point->value());
    }
}
