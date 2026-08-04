<?php

declare(strict_types=1);

namespace Diary\Tests\Property;

use Diary\Ai\SharedTrendAxis;
use Eris\Generator;
use Eris\TestTrait;
use PHPUnit\Framework\TestCase;

/**
 * Property 12: A value's position on the Shared_Trend_Axis is its
 * proportional native-scale position scaled to 0-10.
 *
 * For any native scale [min, max] with max > min - including mood's [1, 10]
 * and sleep's [1, 5] as well as arbitrary randomly generated ranges - and any
 * value within that scale, {@see SharedTrendAxis::positionOf()} places the
 * scale's minimum at 0.0, its maximum at 10.0, and every other value at the
 * same fraction of the way between 0 and 10 as the value is between min and
 * max, and the result never falls outside [0, 10].
 *
 * Requirements: 8.1, 8.2.
 */
final class SharedTrendAxisPropertyTest extends TestCase
{
    use TestTrait;

    // Feature: summary-json-payload, Property 12: A value's position on the Shared_Trend_Axis is its proportional native-scale position scaled to 0-10
    public function testPositionIsProportionalNativeScalePositionScaledTo0To10(): void
    {
        $this->limitTo(100)
            ->forAll(self::scaleAndValue())
            ->then(function (array $tuple): void {
                [$min, $max, $value] = $tuple;

                $minPosition = SharedTrendAxis::positionOf($min, $min, $max);
                $maxPosition = SharedTrendAxis::positionOf($max, $min, $max);
                $valuePosition = SharedTrendAxis::positionOf($value, $min, $max);

                self::assertSame(0.0, $minPosition, 'native minimum must sit at axis position 0.0');
                self::assertSame(10.0, $maxPosition, 'native maximum must sit at axis position 10.0');

                $expected = ($value - $min) / ($max - $min) * 10.0;
                self::assertEqualsWithDelta(
                    $expected,
                    $valuePosition,
                    0.0000001,
                    'position must equal the proportional native-scale position scaled to 0-10'
                );

                self::assertGreaterThanOrEqual(0.0, $valuePosition, 'position must never fall below the axis minimum');
                self::assertLessThanOrEqual(10.0, $valuePosition, 'position must never exceed the axis maximum');
            });
    }

    /**
     * @return \Eris\Generator [min, max, value] with min < max and min <= value <= max
     */
    private static function scaleAndValue(): \Eris\Generator
    {
        return Generator\bind(
            self::nativeScale(),
            static function (array $scale): \Eris\Generator {
                [$min, $max] = $scale;

                return Generator\map(
                    static fn (int $value): array => [$min, $max, $value],
                    Generator\choose($min, $max)
                );
            }
        );
    }

    /**
     * Mood's [1, 10] and sleep's [1, 5] native scales as explicit cases,
     * plus arbitrary randomly generated [min, max] ranges with max > min.
     *
     * @return \Eris\Generator [min, max]
     */
    private static function nativeScale(): \Eris\Generator
    {
        return Generator\oneOf(
            Generator\constant([1, 10]),
            Generator\constant([1, 5]),
            self::randomScale()
        );
    }

    /**
     * @return \Eris\Generator [min, max] with min < max, guaranteed by
     *     bumping max up by one on the (rare) equal-bounds draw
     */
    private static function randomScale(): \Eris\Generator
    {
        return Generator\map(
            static function (array $bounds): array {
                $min = min($bounds[0], $bounds[1]);
                $max = max($bounds[0], $bounds[1]);

                if ($min === $max) {
                    $max = $min + 1;
                }

                return [$min, $max];
            },
            Generator\tuple(Generator\choose(-100, 100), Generator\choose(-100, 100))
        );
    }
}
