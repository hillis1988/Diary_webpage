<?php

declare(strict_types=1);

namespace Diary\Tests\Property;

use Diary\Ai\AiConfig;
use Diary\Ai\HttpResponse;
use Diary\Ai\HttpsSummaryProvider;
use Diary\Ai\HttpTransportException;
use Diary\Ai\ProviderError;
use Diary\Ai\SeriesStats;
use Diary\Ai\SummaryEntryContent;
use Diary\Ai\SummaryInput;
use Diary\Ai\TrendDirection;
use Diary\Ai\TrendMetrics;
use Diary\Support\DateRange;
use Diary\Support\LocalDate;
use Diary\Tests\Unit\Ai\FakeHttpTransport;
use Eris\Generator;
use Eris\TestTrait;
use PHPUnit\Framework\TestCase;

/**
 * Property 6: Every attempt failure is retried exactly once, and only a
 * second failure raises ProviderError.
 *
 * For a fixed, valid SummaryInput and for any pair of configured attempt
 * outcomes (each independently either "succeed with a given valid response"
 * or fail - by a transport-level exception, a non-2xx status, a non-JSON
 * body, or a syntactically-JSON-but-contract-invalid body blanking one of
 * the five required string fields), HttpsSummaryProvider::generate() makes
 * exactly one request if the first attempt succeeds, returning that
 * attempt's ProgressSummary; makes exactly two requests if the first
 * attempt fails and the second succeeds, returning the *second* attempt's
 * ProgressSummary; and makes exactly two requests and raises ProviderError
 * if both attempts fail.
 *
 * The fifth failure shape from the design - a JSON-encoding failure caused
 * by invalid-UTF-8 entry content - is deliberately NOT one of the outcomes
 * generated per attempt slot here. Unlike the other four failure shapes, it
 * is not a property of the *response* HttpsSummaryProvider receives for a
 * given attempt; it is a property of the SummaryInput itself, and
 * `buildRequestBody()` re-encodes the *same* payload on every attempt, so an
 * invalid-UTF-8 SummaryInput fails identically (and without ever reaching
 * the transport) on both attempts - it can never be "the first attempt
 * fails, the second succeeds" or "only attempt one fails". Parameterising
 * it per-slot the same way as the other four failure shapes would therefore
 * either be redundant with the always-both-fail case or actively
 * misleading. It is instead covered by its own fixed example test below,
 * `testGenerateRaisesProviderErrorWithoutAnyTransportCallWhenEntryContentIsNotValidUtf8()`.
 *
 * Requirements: 2.3, 5.2, 5.3, 5.5, 13.1, 13.2, 13.3.
 */
final class SummaryRetryOnceThenFailPropertyTest extends TestCase
{
    use TestTrait;

    // Feature: summary-json-payload, Property 6: Every attempt failure is retried exactly once, and only a second failure raises ProviderError
    public function testEveryAttemptFailureIsRetriedExactlyOnceAndOnlyASecondFailureRaisesProviderError(): void
    {
        $this->limitTo(100)
            ->forAll(self::attemptOutcome(), self::attemptOutcome())
            ->then(function (array $slot1, array $slot2): void {
                $transport = new FakeHttpTransport();
                self::queueOutcome($transport, $slot1);
                self::queueOutcome($transport, $slot2);
                $provider = new HttpsSummaryProvider($transport, self::config());

                if ($slot1['type'] === 'success') {
                    $summary = $provider->generate(self::input());

                    self::assertSame($slot1['narrative'], $summary->narrative());
                    self::assertSame(1, $transport->callCount());

                    return;
                }

                if ($slot2['type'] === 'success') {
                    $summary = $provider->generate(self::input());

                    self::assertSame($slot2['narrative'], $summary->narrative());
                    self::assertSame(2, $transport->callCount());

                    return;
                }

                $threwProviderError = false;

                try {
                    $provider->generate(self::input());
                } catch (ProviderError) {
                    $threwProviderError = true;
                }

                self::assertTrue($threwProviderError, 'Expected ProviderError when both attempts fail.');
                self::assertSame(2, $transport->callCount());
            });
    }

    /**
     * The JSON-encoding failure shape (design.md's fifth failure shape):
     * invalid-UTF-8 content in an entry field makes buildRequestBody()'s
     * json_encode() throw JsonException on *every* attempt, since both
     * attempts re-encode the same SummaryPayload built once from the same
     * SummaryInput. The transport is therefore never reached on either
     * attempt - callCount() stays 0 - yet the retry loop still runs twice
     * before giving up and raising ProviderError.
     */
    public function testGenerateRaisesProviderErrorWithoutAnyTransportCallWhenEntryContentIsNotValidUtf8(): void
    {
        $transport = new FakeHttpTransport();
        $provider = new HttpsSummaryProvider($transport, self::config());

        $stats = SeriesStats::of(3, 6.0, 5, 7, TrendDirection::Stable);
        $invalidEntry = new SummaryEntryContent(
            LocalDate::fromString('2025-03-01'),
            7,
            3,
            "Invalid UTF-8 byte sequence follows: \xB1\x31",
            'Felt okay',
            'calm',
        );
        $input = new SummaryInput(
            DateRange::of(LocalDate::fromString('2025-03-01'), LocalDate::fromString('2025-03-31')),
            TrendMetrics::of(3, $stats, $stats),
            [$invalidEntry],
            [],
        );

        $this->expectException(ProviderError::class);

        try {
            $provider->generate($input);
        } finally {
            self::assertSame(0, $transport->callCount());
        }
    }

    /**
     * Queues the FakeHttpTransport response (or failure) corresponding to
     * one generated attempt outcome, in call order.
     *
     * @param array{type: string, narrative?: string, status?: int, field?: string} $outcome
     */
    private static function queueOutcome(FakeHttpTransport $transport, array $outcome): void
    {
        switch ($outcome['type']) {
            case 'success':
                $transport->queue(self::successResponse($outcome['narrative']));
                break;
            case 'transport_exception':
                $transport->queueFailure(new HttpTransportException('connection reset'));
                break;
            case 'non_2xx':
                $transport->queue(new HttpResponse($outcome['status'], 'error body'));
                break;
            case 'non_json':
                $transport->queue(new HttpResponse(200, 'not valid json'));
                break;
            case 'contract_invalid':
                $transport->queue(self::contractInvalidResponse($outcome['field']));
                break;
        }
    }

    private static function input(): SummaryInput
    {
        $stats = SeriesStats::of(3, 6.0, 5, 7, TrendDirection::Stable);

        return new SummaryInput(
            DateRange::of(LocalDate::fromString('2025-03-01'), LocalDate::fromString('2025-03-31')),
            TrendMetrics::of(3, $stats, $stats),
            [new SummaryEntryContent(LocalDate::fromString('2025-03-01'), 7, 3, 'Walked outside', 'Felt okay', 'calm')],
            [],
        );
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

    private static function successResponse(string $narrative): HttpResponse
    {
        $content = json_encode([
            'summary' => $narrative,
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

    /**
     * A syntactically valid, strictly-JSON response whose decoded body is
     * missing/blanking exactly one of the five contract-required string
     * fields, chosen by $field: "summary", or one of the four advice
     * sub-fields ("pattern", "distortions", "balanced_perspective",
     * "next_action").
     */
    private static function contractInvalidResponse(string $field): HttpResponse
    {
        $decoded = [
            'summary' => 'Mood has been steady this month.',
            'advice' => [
                'pattern' => 'Noticing a pattern of negative self-talk.',
                'distortions' => 'Catastrophizing and all-or-nothing thinking.',
                'balanced_perspective' => 'One difficult week does not define the whole month.',
                'next_action' => 'Try writing down one positive moment each day.',
            ],
        ];

        if ($field === 'summary') {
            $decoded['summary'] = '';
        } else {
            $decoded['advice'][$field] = '';
        }

        $content = json_encode($decoded, JSON_THROW_ON_ERROR);
        $body = json_encode([
            'choices' => [
                ['message' => ['content' => $content]],
            ],
        ], JSON_THROW_ON_ERROR);

        return new HttpResponse(200, $body);
    }

    /**
     * One of the two attempt-slot outcomes: success with a randomly
     * generated non-empty narrative, or one of the four per-attempt failure
     * shapes (transport exception, non-2xx status, non-JSON body, or a
     * contract-invalid body blanking a randomly chosen required field).
     */
    private static function attemptOutcome(): \Eris\Generator
    {
        return Generator\oneOf(
            self::successOutcome(),
            self::transportExceptionOutcome(),
            self::non2xxOutcome(),
            self::nonJsonBodyOutcome(),
            self::contractInvalidOutcome(),
        );
    }

    private static function successOutcome(): \Eris\Generator
    {
        return Generator\map(
            static fn (string $narrative): array => ['type' => 'success', 'narrative' => $narrative],
            self::narrativeText(),
        );
    }

    private static function transportExceptionOutcome(): \Eris\Generator
    {
        return Generator\constant(['type' => 'transport_exception']);
    }

    private static function non2xxOutcome(): \Eris\Generator
    {
        return Generator\map(
            static fn (int $status): array => ['type' => 'non_2xx', 'status' => $status],
            Generator\oneOf(Generator\choose(400, 499), Generator\choose(500, 599)),
        );
    }

    private static function nonJsonBodyOutcome(): \Eris\Generator
    {
        return Generator\constant(['type' => 'non_json']);
    }

    private static function contractInvalidOutcome(): \Eris\Generator
    {
        return Generator\map(
            static fn (string $field): array => ['type' => 'contract_invalid', 'field' => $field],
            Generator\elements(['summary', 'pattern', 'distortions', 'balanced_perspective', 'next_action']),
        );
    }

    /**
     * Arbitrary non-empty narrative text - the fixed "Summary " prefix
     * guarantees non-emptiness regardless of the random suffix, which
     * matters here because a would-be-empty narrative would itself be a
     * contract-invalid outcome rather than a success one.
     */
    private static function narrativeText(): \Eris\Generator
    {
        return Generator\map(
            static fn (array $characters): string => 'Summary ' . implode('', $characters),
            Generator\vector(10, Generator\elements(str_split('abcdefghijklmnopqrstuvwxyz ., '))),
        );
    }
}
