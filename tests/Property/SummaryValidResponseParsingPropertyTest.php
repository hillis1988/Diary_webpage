<?php

declare(strict_types=1);

namespace Diary\Tests\Property;

use Diary\Ai\AiConfig;
use Diary\Ai\HttpResponse;
use Diary\Ai\HttpsSummaryProvider;
use Diary\Ai\SeriesStats;
use Diary\Ai\SummaryEntryContent;
use Diary\Ai\SummaryInput;
use Diary\Ai\SummaryMilestoneContent;
use Diary\Ai\TrendDirection;
use Diary\Ai\TrendMetrics;
use Diary\Milestone\MilestoneCategory;
use Diary\Support\DateRange;
use Diary\Support\LocalDate;
use Diary\Tests\Unit\Ai\FakeHttpTransport;
use Eris\Generator;
use Eris\TestTrait;
use PHPUnit\Framework\TestCase;

/**
 * Property 7: A valid response parses into a Progress_Summary carrying
 * exactly its own fields and that request's metrics.
 *
 * For any SummaryInput and any valid response body (non-empty summary and
 * non-empty pattern/distortions/balanced_perspective/next_action advice
 * fields), the ProgressSummary returned by HttpsSummaryProvider::generate()
 * carries the response's own narrative/advice fields exactly, and its
 * metrics are exactly the metrics that were part of the request - never
 * recomputed or altered.
 *
 * Requirements: 5.1, 5.4.
 */
final class SummaryValidResponseParsingPropertyTest extends TestCase
{
    use TestTrait;

    // Feature: summary-json-payload, Property 7: A valid response parses into a Progress_Summary carrying exactly its own fields and that request's metrics
    public function testAValidResponseParsesIntoAProgressSummaryCarryingExactlyItsOwnFieldsAndThatRequestsMetrics(): void
    {
        $this->limitTo(100)
            ->forAll(
                self::dateRange(),
                self::trendMetrics(),
                self::entryList(),
                self::milestoneList(),
                self::nonEmptyText(),
                self::nonEmptyText(),
                self::nonEmptyText(),
                self::nonEmptyText(),
                self::nonEmptyText()
            )
            ->then(function (
                DateRange $range,
                TrendMetrics $metrics,
                array $entries,
                array $milestones,
                string $summaryText,
                string $pattern,
                string $distortions,
                string $balancedPerspective,
                string $nextAction
            ): void {
                $input = new SummaryInput($range, $metrics, $entries, $milestones);

                $transport = new FakeHttpTransport();
                $transport->queue(self::successResponse(
                    $summaryText,
                    $pattern,
                    $distortions,
                    $balancedPerspective,
                    $nextAction
                ));
                $provider = new HttpsSummaryProvider($transport, self::config());

                $summary = $provider->generate($input);

                self::assertSame($summaryText, $summary->narrative());
                self::assertSame($pattern, $summary->advice()->pattern());
                self::assertSame($distortions, $summary->advice()->distortions());
                self::assertSame($balancedPerspective, $summary->advice()->balancedPerspective());
                self::assertSame($nextAction, $summary->advice()->nextAction());
                self::assertSame($input->metrics(), $summary->metrics());
            });
    }

    private static function successResponse(
        string $summary,
        string $pattern,
        string $distortions,
        string $balancedPerspective,
        string $nextAction
    ): HttpResponse {
        $content = json_encode([
            'summary' => $summary,
            'advice' => [
                'pattern' => $pattern,
                'distortions' => $distortions,
                'balanced_perspective' => $balancedPerspective,
                'next_action' => $nextAction,
            ],
        ], JSON_THROW_ON_ERROR);
        $body = json_encode([
            'choices' => [
                ['message' => ['content' => $content]],
            ],
        ], JSON_THROW_ON_ERROR);

        return new HttpResponse(200, $body);
    }

    private static function config(): AiConfig
    {
        return AiConfig::fromConfig(['ai' => [
            'enabled' => true,
            'provider' => 'example-provider',
            'endpoint' => 'https://api.example.com/v1/chat/completions',
            'api_key' => 'secret-key',
            'model' => 'example-model',
            'timeout_seconds' => 20,
            'retries' => 1,
        ]]);
    }

    private static function dateRange(): \Eris\Generator
    {
        return Generator\map(
            static fn (array $dates): DateRange => DateRange::of($dates[0], $dates[1]),
            Generator\tuple(self::localDate(), self::localDate())
        );
    }

    private static function localDate(): \Eris\Generator
    {
        return Generator\map(
            static fn (array $parts): LocalDate => LocalDate::of($parts[0], $parts[1], $parts[2]),
            Generator\tuple(
                Generator\choose(2020, 2030),
                Generator\choose(1, 12),
                Generator\choose(1, 28)
            )
        );
    }

    private static function trendMetrics(): \Eris\Generator
    {
        return Generator\map(
            static fn (array $parts): TrendMetrics => TrendMetrics::of($parts[0], $parts[1], $parts[2]),
            Generator\tuple(
                Generator\choose(0, 30),
                self::seriesStats(),
                self::seriesStats()
            )
        );
    }

    /**
     * Either an empty series (count 0, mean/min/max null) or a non-empty
     * series with arbitrary count/mean/min/max/direction.
     */
    private static function seriesStats(): \Eris\Generator
    {
        return Generator\oneOf(
            Generator\constant(SeriesStats::of(0, null, null, null, TrendDirection::Stable)),
            Generator\map(
                static function (array $parts): SeriesStats {
                    [$count, $mean, $min, $max, $direction] = $parts;
                    $lower = min($min, $max);
                    $upper = max($min, $max);

                    return SeriesStats::of($count, $mean, $lower, $upper, $direction);
                },
                Generator\tuple(
                    Generator\choose(1, 30),
                    Generator\map(static fn (int $value): float => $value / 10, Generator\choose(-100, 100)),
                    Generator\choose(1, 10),
                    Generator\choose(1, 10),
                    Generator\elements(TrendDirection::cases())
                )
            )
        );
    }

    private static function entryList(): \Eris\Generator
    {
        return Generator\bind(
            Generator\choose(0, 5),
            static fn (int $length): \Eris\Generator => $length === 0
                ? Generator\constant([])
                : Generator\vector($length, self::entryContent())
        );
    }

    private static function entryContent(): \Eris\Generator
    {
        return Generator\map(
            static fn (array $parts): SummaryEntryContent => new SummaryEntryContent(
                $parts[0],
                $parts[1],
                $parts[2],
                $parts[3],
                $parts[4],
                $parts[5]
            ),
            Generator\tuple(
                self::localDate(),
                Generator\choose(1, 10),
                Generator\oneOf(Generator\constant(null), Generator\choose(1, 5)),
                self::freeText(),
                self::freeText(),
                self::freeText()
            )
        );
    }

    private static function milestoneList(): \Eris\Generator
    {
        return Generator\bind(
            Generator\choose(0, 5),
            static fn (int $length): \Eris\Generator => $length === 0
                ? Generator\constant([])
                : Generator\vector($length, self::milestoneContent())
        );
    }

    private static function milestoneContent(): \Eris\Generator
    {
        return Generator\map(
            static fn (array $parts): SummaryMilestoneContent => new SummaryMilestoneContent(
                $parts[0],
                $parts[1],
                $parts[2]
            ),
            Generator\tuple(
                self::localDate(),
                self::freeText(),
                Generator\elements(MilestoneCategory::cases())
            )
        );
    }

    /**
     * Arbitrary text including the empty string - used for the incidental
     * entry/milestone content fields, which are not what this property is
     * about.
     */
    private static function freeText(): \Eris\Generator
    {
        return Generator\oneOf(
            Generator\constant(''),
            Generator\map(
                static fn (array $characters): string => implode('', $characters),
                Generator\vector(
                    10,
                    Generator\elements(str_split('abcdefghijklmnopqrstuvwxyz ., '))
                )
            )
        );
    }

    /**
     * Arbitrary non-empty text - used for the response's own
     * summary/advice fields, since this property is about the valid/success
     * path where each of those fields must be a non-empty string.
     */
    private static function nonEmptyText(): \Eris\Generator
    {
        return Generator\map(
            static fn (array $characters): string => implode('', $characters),
            Generator\vector(
                10,
                Generator\elements(str_split('abcdefghijklmnopqrstuvwxyzABCDEFGHIJ .,!?'))
            )
        );
    }
}
