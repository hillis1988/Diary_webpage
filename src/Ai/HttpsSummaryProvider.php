<?php

declare(strict_types=1);

namespace Diary\Ai;

use JsonException;

/**
 * Provider-agnostic HTTPS adapter over a generic chat-completion style LLM
 * API for progress summaries (Requirements 9.1-9.3). Mirrors {@see
 * HttpsFeedbackProvider}'s behaviour exactly, reusing the same
 * {@see HttpTransport} and {@see AiConfig}, differing only in the prompt
 * builder and the shape of the expected JSON response.
 *
 * Behaviour:
 *
 *   - When AI is disabled by configuration, `generate()` throws
 *     {@see ProviderError} immediately and makes no network call at all.
 *   - The request asks for strict JSON output and sends only the
 *     pseudonymised prompt built by {@see SummaryPromptBuilder} - never an
 *     account identifier, email address or name (Requirement 9.1).
 *   - One retry: a failed attempt (network error, non-2xx, or a body that is
 *     not valid JSON) is tried exactly once more before giving up.
 *   - Every failure path raises {@see ProviderError}, which
 *     AI_Summary_Service catches to fall back to `Unavailable`
 *     (Requirement 9.5).
 */
final class HttpsSummaryProvider implements SummaryProvider
{
    public function __construct(
        private readonly HttpTransport $transport,
        private readonly AiConfig $config,
        private readonly SummaryPromptBuilder $promptBuilder = new SummaryPromptBuilder(),
    ) {
    }

    public function generate(SummaryInput $input): ProgressSummary
    {
        if (!$this->config->enabled()) {
            throw new ProviderError('AI summary is disabled by configuration.');
        }

        $payload = $this->promptBuilder->buildPayload($input);
        $headers = [
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $this->config->apiKey(),
        ];

        // One retry: attempt 0 is the first try, attempt 1 is the single retry.
        $attempts = 2;
        $lastFailure = null;

        for ($attempt = 0; $attempt < $attempts; $attempt++) {
            try {
                $requestBody = $this->buildRequestBody($payload);
                $response = $this->transport->post(
                    $this->config->endpoint(),
                    $headers,
                    $requestBody,
                    $this->config->timeoutSeconds(),
                );

                return $this->parseSummary($response, $input->metrics());
            } catch (JsonException|HttpTransportException|ProviderError $exception) {
                $lastFailure = $exception;
            }
        }

        throw new ProviderError(
            'The AI provider did not return a usable summary after retrying.',
            0,
            $lastFailure
        );
    }

    private function buildRequestBody(SummaryPayload $payload): string
    {
        $userContent = json_encode($payload, JSON_THROW_ON_ERROR);
        $body = [
            'model' => $this->config->model(),
            'response_format' => ['type' => 'json_object'],
            'messages' => [
                ['role' => 'system', 'content' => $this->promptBuilder->systemPrompt()],
                ['role' => 'user', 'content' => $userContent],
            ],
        ];

        return json_encode($body, JSON_THROW_ON_ERROR);
    }

    /**
     * @throws ProviderError when the response is not a 2xx, or its body is
     *                       not valid strict JSON carrying the expected
     *                       string field
     */
    private function parseSummary(HttpResponse $response, TrendMetrics $metrics): ProgressSummary
    {
        if (!$response->isSuccessful()) {
            throw new ProviderError(sprintf(
                'The AI provider responded with HTTP status %d.',
                $response->statusCode()
            ));
        }

        $envelope = $this->decodeJson($response->body());
        $content = $this->messageContent($envelope);
        $decoded = $this->decodeJson($content);

        $summary = $decoded['summary'] ?? null;

        if (!is_string($summary) || $summary === '') {
            throw new ProviderError(
                'The AI provider response did not contain the expected summary field.'
            );
        }

        $advice = $decoded['advice'] ?? null;
        $advice = is_array($advice) ? $advice : [];

        $fields = [];
        foreach (['pattern', 'distortions', 'balanced_perspective', 'next_action'] as $key) {
            $value = $advice[$key] ?? null;

            if (!is_string($value) || $value === '') {
                throw new ProviderError(
                    'The AI provider response did not contain a valid advice.' . $key . ' field.'
                );
            }

            $fields[$key] = $value;
        }

        return new ProgressSummary(
            $summary,
            new CbtAdvice(
                $fields['pattern'],
                $fields['distortions'],
                $fields['balanced_perspective'],
                $fields['next_action'],
            ),
            $metrics,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJson(string $json): array
    {
        try {
            $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new ProviderError('The AI provider response was not valid JSON.', 0, $exception);
        }

        if (!is_array($decoded)) {
            throw new ProviderError('The AI provider response was not a JSON object.');
        }

        return $decoded;
    }

    /**
     * @param array<string, mixed> $envelope
     */
    private function messageContent(array $envelope): string
    {
        $choices = $envelope['choices'] ?? null;

        if (!is_array($choices) || !isset($choices[0]) || !is_array($choices[0])) {
            throw new ProviderError('The AI provider response did not contain any choices.');
        }

        $message = $choices[0]['message'] ?? null;
        $content = is_array($message) ? ($message['content'] ?? null) : null;

        if (!is_string($content) || $content === '') {
            throw new ProviderError('The AI provider response did not contain a message body.');
        }

        return $content;
    }
}
