<?php

declare(strict_types=1);

namespace Diary\Tests\Unit\Ai;

use Diary\Ai\AiConfig;
use Diary\Ai\EntryContent;
use Diary\Ai\HttpResponse;
use Diary\Ai\HttpsFeedbackProvider;
use Diary\Ai\HttpTransportException;
use Diary\Ai\ProviderError;
use PHPUnit\Framework\TestCase;

/**
 * Requirement 6.1: the adapter is a provider-agnostic HTTPS call requesting
 * strict JSON, with a 20-second timeout, exactly one retry, and a
 * {@see ProviderError} on failure. All of this is exercised against a
 * {@see FakeHttpTransport} - no test here ever makes a real network call.
 */
final class HttpsFeedbackProviderTest extends TestCase
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

    private function content(): EntryContent
    {
        return new EntryContent(7, 3, 'Went for a walk.', 'Felt calmer.', 'Content');
    }

    private function successResponse(string $positiveFocus = 'You went for a walk.', string $suggestedChange = 'Try a short walk tomorrow too.'): HttpResponse
    {
        $body = json_encode([
            'choices' => [
                ['message' => ['content' => json_encode([
                    'positive_focus' => $positiveFocus,
                    'suggested_change' => $suggestedChange,
                ], JSON_THROW_ON_ERROR)]],
            ],
        ], JSON_THROW_ON_ERROR);

        return new HttpResponse(200, $body);
    }

    public function testGenerateReturnsTheRecommendationOnASuccessfulFirstAttempt(): void
    {
        $transport = new FakeHttpTransport();
        $transport->queue($this->successResponse());
        $provider = new HttpsFeedbackProvider($transport, $this->config());

        $recommendation = $provider->generate($this->content());

        self::assertSame('You went for a walk.', $recommendation->positiveFocus());
        self::assertSame('Try a short walk tomorrow too.', $recommendation->suggestedChange());
        self::assertSame(1, $transport->callCount());
    }

    public function testGenerateSendsTheConfiguredTimeoutToTheTransport(): void
    {
        $transport = new FakeHttpTransport();
        $transport->queue($this->successResponse());
        $provider = new HttpsFeedbackProvider($transport, $this->config(['timeout_seconds' => 20]));

        $provider->generate($this->content());

        self::assertSame(20, $transport->calls()[0]['timeoutSeconds']);
    }

    public function testGenerateRetriesExactlyOnceAfterATransportFailureThenSucceeds(): void
    {
        $transport = new FakeHttpTransport();
        $transport->queueFailure(new HttpTransportException('connection reset'));
        $transport->queue($this->successResponse());
        $provider = new HttpsFeedbackProvider($transport, $this->config());

        $recommendation = $provider->generate($this->content());

        self::assertSame('You went for a walk.', $recommendation->positiveFocus());
        self::assertSame(2, $transport->callCount());
    }

    public function testGenerateThrowsProviderErrorAfterTheRetryIsExhaustedOnRepeatedTimeouts(): void
    {
        $transport = new FakeHttpTransport();
        $transport->queueFailure(new HttpTransportException('timed out'));
        $transport->queueFailure(new HttpTransportException('timed out'));
        $provider = new HttpsFeedbackProvider($transport, $this->config());

        $this->expectException(ProviderError::class);

        try {
            $provider->generate($this->content());
        } finally {
            self::assertSame(2, $transport->callCount());
        }
    }

    public function testGenerateThrowsProviderErrorOnANonSuccessfulStatusAfterTheRetry(): void
    {
        $transport = new FakeHttpTransport();
        $transport->queue(new HttpResponse(500, 'Internal Server Error'));
        $transport->queue(new HttpResponse(500, 'Internal Server Error'));
        $provider = new HttpsFeedbackProvider($transport, $this->config());

        $this->expectException(ProviderError::class);

        try {
            $provider->generate($this->content());
        } finally {
            self::assertSame(2, $transport->callCount());
        }
    }

    public function testGenerateThrowsProviderErrorOnMalformedJsonAfterTheRetry(): void
    {
        $transport = new FakeHttpTransport();
        $transport->queue(new HttpResponse(200, 'not json'));
        $transport->queue(new HttpResponse(200, 'still not json'));
        $provider = new HttpsFeedbackProvider($transport, $this->config());

        $this->expectException(ProviderError::class);

        try {
            $provider->generate($this->content());
        } finally {
            self::assertSame(2, $transport->callCount());
        }
    }

    public function testGenerateThrowsProviderErrorWhenTheResponseIsMissingExpectedFields(): void
    {
        $body = json_encode([
            'choices' => [
                ['message' => ['content' => json_encode(['positive_focus' => 'Only one field.'], JSON_THROW_ON_ERROR)]],
            ],
        ], JSON_THROW_ON_ERROR);
        $transport = new FakeHttpTransport();
        $transport->queue(new HttpResponse(200, $body));
        $transport->queue(new HttpResponse(200, $body));
        $provider = new HttpsFeedbackProvider($transport, $this->config());

        $this->expectException(ProviderError::class);
        $provider->generate($this->content());
    }

    /**
     * `parseRecommendation()` only extracts `positive_focus` and
     * `suggested_change` by key; anything else in the decoded object is
     * outside the two fields Requirements 6.2 and 6.3 name, and is ignored
     * rather than causing rejection.
     */
    public function testGenerateAcceptsAResponseCarryingAdditionalUnexpectedFields(): void
    {
        $body = json_encode([
            'choices' => [
                ['message' => ['content' => json_encode([
                    'positive_focus' => 'You went for a walk.',
                    'suggested_change' => 'Try a short walk tomorrow too.',
                    'confidence' => 0.87,
                    'extra_field' => ['nested' => true],
                ], JSON_THROW_ON_ERROR)]],
            ],
        ], JSON_THROW_ON_ERROR);
        $transport = new FakeHttpTransport();
        $transport->queue(new HttpResponse(200, $body));
        $provider = new HttpsFeedbackProvider($transport, $this->config());

        $recommendation = $provider->generate($this->content());

        self::assertSame('You went for a walk.', $recommendation->positiveFocus());
        self::assertSame('Try a short walk tomorrow too.', $recommendation->suggestedChange());
        self::assertSame(1, $transport->callCount());
    }

    public function testGenerateThrowsProviderErrorImmediatelyWhenAiIsDisabledAndMakesNoNetworkCall(): void
    {
        $transport = new FakeHttpTransport();
        $provider = new HttpsFeedbackProvider($transport, $this->config(['enabled' => false]));

        $this->expectException(ProviderError::class);

        try {
            $provider->generate($this->content());
        } finally {
            self::assertSame(0, $transport->callCount());
        }
    }

    public function testGenerateNeverSendsAnAccountIdentifierEmailAddressOrNameInTheRequestBody(): void
    {
        $transport = new FakeHttpTransport();
        $transport->queue($this->successResponse());
        $provider = new HttpsFeedbackProvider($transport, $this->config());

        $provider->generate($this->content());

        $sentBody = mb_strtolower($transport->calls()[0]['body']);
        self::assertStringNotContainsString('@', $sentBody);
        self::assertStringNotContainsString('roy', $sentBody);
        self::assertStringNotContainsString('account', $sentBody);
    }
}
