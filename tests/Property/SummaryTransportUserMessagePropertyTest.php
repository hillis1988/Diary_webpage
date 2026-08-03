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
use Diary\Ai\SummaryPromptBuilder;
use Diary\Ai\TrendDirection;
use Diary\Ai\TrendMetrics;
use Diary\Milestone\MilestoneCategory;
use Diary\Support\DateRange;
use Diary\Support\LocalDate;
use Diary\Tests\Unit\Ai\FakeHttpTransport;
use Eris\Generator;
use Eris\TestTrait;
use JsonException;
use PHPUnit\Framework\TestCase;

/**
 * Property 5: The JSON transport sends exactly the payload's encoding as the
 * user message.
 *
 * For any SummaryInput, the request captured by a recording HttpTransport
 * fake carries a user message whose content, JSON-decoded, is deep-equal to
 * the SummaryPayload built from that input's JSON-serialized form, with no
 * other text appended before or after it.
 *
 * The call to HttpsSummaryProvider::generate() is wrapped in try/catch
 * (\Throwable) because this property is only about what was *sent* - the
 * request is captured by the fake transport before any response parsing
 * happens, so it is valid to inspect regardless of whether generate()
 * ultimately succeeds or throws while parsing the (fixed, valid-shaped)
 * queued response.
 *
 * Requirements: 2.1, 2.2.
 */
final class SummaryTransportUserMessagePropertyTest extends TestCase
{
    use TestTrait;

    // Feature: summary-json-payload, Property 5: The JSON transport sends exactly the payload's encoding as the user message
    public function testTheJsonTransportSendsExactlyThePayloadsEncodingAsTheUserMessage(): void
    {
        $this->limitTo(100)
            ->forAll(
                self::dateRange(),
                self::trendMetrics(),
                self::entryList(),
                self::milestoneList()
            )
            ->then(function (
                DateRange $range,
                TrendMetrics $metrics,
                array $entries,
                array $milestones
            ): void {
                $input = new SummaryInput($range, $metrics, $entries, $milestones);

                $expectedPayload = (new SummaryPromptBuilder())->buildPayload($input);
                $expectedArray = self::toArray($expectedPayload);

                $transport = new FakeHttpTransport();
                $transport->queue(self::successResponse());
                $provider = new HttpsSummaryProvider($transport, self::config());

                try {
                    $provider->generate($input);
                } catch (\Throwable) {
                    // Property 5 is only about the request that was sent, which was
                    // already captured by the fake transport before any parsing
                    // happens - the outcome of generate() itself is irrelevant here.
                }

                self::assertGreaterThanOrEqual(1, $transport->callCount());
                $call = $transport->calls()[0];

                $requestEnvelope = self::decode($call['body']);
                self::assertArrayHasKey('messages', $requestEnvelope);
                self::assertIsArray($requestEnvelope['messages']);
                self::assertCount(2, $requestEnvelope['messages'], 'Expected exactly a system message and a user message.');

                self::assertSame('system', $requestEnvelope['messages'][0]['role']);
                self::assertSame('user', $requestEnvelope['messages'][1]['role']);

                $userContent = $requestEnvelope['messages'][1]['content'];
                self::assertIsString($userContent);

                // A successful, strict json_decode() of the entire string (no
                // JSON_BIGINT_AS_STRING quirks needed here) already proves no
                // other text was appended before or after the JSON structure -
                // any leading or trailing non-whitespace garbage would fail to
                // parse as valid JSON.
                $decodedUserContent = self::decode($userContent);

                self::assertEquals($expectedArray, $decodedUserContent);
            });
    }

    /**
     * @return array<string, mixed>
     */
    private static function toArray(\JsonSerializable $value): array
    {
        return self::decode(json_encode($value, JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<string, mixed>
     */
    private static function decode(string $json): array
    {
        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            self::fail('Expected valid JSON but decoding failed: ' . $exception->getMessage());
        }

        self::assertIsArray($decoded);

        return $decoded;
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

    private static function successResponse(): HttpResponse
    {
        $content = json_encode([
            'summary' => 'Mood has been steady this month.',
            'advice' => [
                'pattern' => 'Noticing a pattern of negative self-talk.',
                'distortions' => 'Catastrophizing and all-or-nothing thinking.',
                'balanced_perspective' => 'One difficult week does not define the whole month.',
                'next_action' => 'Try writing down one positive moment each day.',
            ],
        ], JSON_THROW_ON_ERROR);
        $body = json_encode([
            'choices' => [
                ['message' => ['content' => $content]],
            ],
        ], JSON_THROW_ON_ERROR);

        return new HttpResponse(200, $body);
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
     * Arbitrary text including the empty string, exercising the
     * "empty string preserved, never omitted" edge case for record fields.
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
}
