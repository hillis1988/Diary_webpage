<?php

declare(strict_types=1);

namespace Diary\Tests\Property;

use Diary\Ai\SharedTrendAxis;
use Diary\Ai\TrendPoint;
use Diary\Http\SummaryController;
use Diary\Support\LocalDate;
use Eris\Generator;
use Eris\TestTrait;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * The Trend_Line_Chart's plotted point coordinates are computed from
 * SharedTrendAxis::positionOf() consistently with the rest of the summary
 * page - there is no second, independent scaling formula for the chart's y
 * axis.
 *
 * For any list of dated TrendPoints and any native scale, each point's
 * plotted y-coordinate (read back via SummaryController's private
 * coordinate helper through reflection) equals the chart's fixed plot
 * height minus SharedTrendAxis::positionOf($point->value(), $scaleMin,
 * $scaleMax) / 10 * plotHeight - i.e. the same shared-axis fraction the
 * numeric labels are deliberately kept independent from, only inverted for
 * SVG's downward-growing y axis.
 */
final class TrendLineChartCoordinatePropertyTest extends TestCase
{
    use TestTrait;

    private const CHART_TOP_Y = 5.0;
    private const CHART_BOTTOM_Y = 55.0;
    private const CHART_PLOT_HEIGHT = self::CHART_BOTTOM_Y - self::CHART_TOP_Y;

    // Feature: summary-json-payload, Property: the line chart's point coordinates are computed from SharedTrendAxis::positionOf() consistently with the existing axis
    public function testPlottedYCoordinateMatchesSharedTrendAxisPositionInverted(): void
    {
        $this->limitTo(100)
            ->forAll(
                self::scaleAndPoints()
            )
            ->then(function (array $shape): void {
                [$scaleMin, $scaleMax, $points] = $shape;

                $coordinates = self::plottedCoordinates($points, $scaleMin, $scaleMax);

                self::assertCount(count($points), $coordinates);

                foreach ($points as $index => $point) {
                    $expectedAxisPosition = SharedTrendAxis::positionOf($point->value(), $scaleMin, $scaleMax);
                    $expectedY = self::CHART_BOTTOM_Y - ($expectedAxisPosition / 10) * self::CHART_PLOT_HEIGHT;

                    self::assertEqualsWithDelta(
                        $expectedY,
                        $coordinates[$index][1],
                        0.005,
                        'The chart y-coordinate must equal SharedTrendAxis::positionOf(), inverted for SVG'
                    );
                }
            });
    }

    /** Invokes SummaryController's private plottedCoordinates() via reflection. */
    private static function plottedCoordinates(array $points, int $scaleMin, int $scaleMax): array
    {
        $ref = new ReflectionClass(SummaryController::class);
        $method = $ref->getMethod('plottedCoordinates');
        $method->setAccessible(true);

        return $method->invoke(null, $points, $scaleMin, $scaleMax);
    }

    /**
     * A native scale [min, max] (max > min) plus a list of 1-10 TrendPoints
     * with values drawn from that scale and consecutive dates.
     *
     * @return \Eris\Generator array{0: int, 1: int, 2: list<TrendPoint>}
     */
    private static function scaleAndPoints(): \Eris\Generator
    {
        return Generator\bind(
            Generator\tuple(Generator\choose(1, 5), Generator\choose(6, 12)),
            static function (array $bounds): \Eris\Generator {
                [$scaleMin, $scaleMax] = $bounds;

                return Generator\map(
                    static function (array $values) use ($scaleMin, $scaleMax): array {
                        $date = LocalDate::of(2025, 1, 1);
                        $points = [];
                        foreach ($values as $i => $value) {
                            $points[] = new TrendPoint($date->plusDays($i), $value);
                        }

                        return [$scaleMin, $scaleMax, $points];
                    },
                    Generator\vector(5, Generator\choose($scaleMin, $scaleMax))
                );
            }
        );
    }
}
