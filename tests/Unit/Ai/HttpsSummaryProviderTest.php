<?php

declare(strict_types=1);

namespace Diary\Tests\Unit\Ai;

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
use PHPUnit\Framework\TestCase;

/**
 * Requirement 9.1: the summary adapter is a provider-agnostic HTTPS call
 * requesting strict JSON, with a 20-second timeout, exactly one retry, and a
 * {@see ProviderError} on failure. All of this is exercised against a
 * {@see FakeHttpTransport} - no test here ever makes a real network call.
 * Mirrors tests/Unit/Ai/HttpsFeedbackProviderTest.php.
 */
final class HttpsSummaryProviderTest extends TestCase
{
    private function config(array $overrides = []): AiConfig
    {
        $defaults = [
            'enabled' => true,
            'provider' => 'example-provider',
            'endpoint' => 'https://api.example.com/v1/chat/completions',
            'api_key' => 'secret-key',
            'model' => 'example-model',
            'timeout_seconds' => 20,
            'retries' => 1,
        ];

        return AiConfig::fromConfig(['ai' => [...$defaults, ...$overrides]]);
    }

    private function input(): SummaryInput
    {
        $stats = SeriesStats::of(3, 6.0, 5, 7, TrendDirection::Stable);

        return new SummaryInput(
            DateRange::of(LocalDate::fromString('2025-03-01'), LocalDate::fromString('2025-03-31')),
            TrendMetrics::of(3, $stats, $stats),
            [new SummaryEntryContent(LocalDate::fromString('2025-03-01'), 7, 3, 'Walked outside', 'Felt okay', 'calm')],
            [],
        );
    }

    private function successResponse(string $narrative = 'Mood has been steady this month.'): HttpResponse
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

    public function testGenerateReturnsTheSummaryOnASuccessfulFirstAttempt(): void
    {
        $transport = new FakeHttpTransport();
        $transport->queue($this->successResponse());
        $provider = new HttpsSummaryProvider($transport, $this->config());

        $summary = $provider->generate($this->input());

        self::assertSame('Mood has been steady this month.', $summary->narrative());
        self::assertSame(1, $transport->callCount());
    }

    public function testGenerateCarriesTheOriginalMetricsForwardOnTheSummary(): void
    {
        $transport = new FakeHttpTransport();
        $transport->queue($this->successResponse());
        $provider = new HttpsSummaryProvider($transport, $this->config());
        $input = $this->input();

        $summary = $provider->generate($input);

        self::assertSame($input->metrics(), $summary->metrics());
    }

    public function testGenerateRetriesExactlyOnceAfterATransportFailureThenSucceeds(): void
    {
        $transport = new FakeHttpTransport();
        $transport->queueFailure(new HttpTransportException('connection reset'));
        $transport->queue($this->successResponse());
        $provider = new HttpsSummaryProvider($transport, $this->config());

        $summary = $provider->generate($this->input());

        self::assertSame('Mood has been steady this month.', $summary->narrative());
        self::assertSame(2, $transport->callCount());
    }

    public function testGenerateThrowsProviderErrorAfterTheRetryIsExhaustedOnRepeatedTimeouts(): void
    {
        $transport = new FakeHttpTransport();
        $transport->queueFailure(new HttpTransportException('timed out'));
        $transport->queueFailure(new HttpTransportException('timed out'));
        $provider = new HttpsSummaryProvider($transport, $this->config());

        $this->expectException(ProviderError::class);

        try {
            $provider->generate($this->input());
        } finally {
            self::assertSame(2, $transport->callCount());
        }
    }

    public function testGenerateThrowsProviderErrorOnANonSuccessfulStatusAfterTheRetry(): void
    {
        $transport = new FakeHttpTransport();
        $transport->queue(new HttpResponse(500, 'Internal Server Error'));
        $transport->queue(new HttpResponse(500, 'Internal Server Error'));
        $provider = new HttpsSummaryProvider($transport, $this->config());

        $this->expectException(ProviderError::class);

        try {
            $provider->generate($this->input());
        } finally {
            self::assertSame(2, $transport->callCount());
        }
    }

    public function testGenerateThrowsProviderErrorOnMalformedJsonAfterTheRetry(): void
    {
        $transport = new FakeHttpTransport();
        $transport->queue(new HttpResponse(200, 'not json'));
        $transport->queue(new HttpResponse(200, 'still not json'));
        $provider = new HttpsSummaryProvider($transport, $this->config());

        $this->expectException(ProviderError::class);

        try {
            $provider->generate($this->input());
        } finally {
            self::assertSame(2, $transport->callCount());
        }
    }

    public function testGenerateThrowsProviderErrorWhenTheResponseIsMissingTheSummaryField(): void
    {
        $body = json_encode([
            'choices' => [
                ['message' => ['content' => json_encode(['something_else' => 'value'], JSON_THROW_ON_ERROR)]],
            ],
        ], JSON_THROW_ON_ERROR);
        $transport = new FakeHttpTransport();
        $transport->queue(new HttpResponse(200, $body));
        $transport->queue(new HttpResponse(200, $body));
        $provider = new HttpsSummaryProvider($transport, $this->config());

        $this->expectException(ProviderError::class);
        $provider->generate($this->input());
    }

    public function testGenerateThrowsProviderErrorImmediatelyWhenAiIsDisabledAndMakesNoNetworkCall(): void
    {
        $transport = new FakeHttpTransport();
        $provider = new HttpsSummaryProvider($transport, $this->config(['enabled' => false]));

        $this->expectException(ProviderError::class);

        try {
            $provider->generate($this->input());
        } finally {
            self::assertSame(0, $transport->callCount());
        }
    }

    public function testGenerateNeverSendsAnAccountIdentifierEmailAddressOrNameInTheRequestBody(): void
    {
        $transport = new FakeHttpTransport();
        $transport->queue($this->successResponse());
        $provider = new HttpsSummaryProvider($transport, $this->config());

        $provider->generate($this->input());

        $sentBody = mb_strtolower($transport->calls()[0]['body']);
        self::assertStringNotContainsString('@', $sentBody);
        self::assertStringNotContainsString('roy', $sentBody);
        self::assertStringNotContainsString('account', $sentBody);
    }
}
