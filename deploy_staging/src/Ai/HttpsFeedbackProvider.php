<?php

declare(strict_types=1);

namespace Diary\Ai;

use JsonException;

/**
 * Provider-agnostic HTTPS adapter over a generic chat-completion style LLM
 * API (Requirement 6.1).
 *
 * "Provider-agnostic" here means the wire format is the common
 * `{"model": ..., "messages": [...]}` request / `{"choices": [{"message":
 * {"content": "..."}}]}` response shape that most hosted chat completion
 * APIs accept, rather than a vendor SDK. Swapping the endpoint, API key and
 * model in configuration is enough to point this adapter at a different
 * provider that speaks the same shape; no vendor-specific class is required
 * for that switch.
 *
 * Behaviour:
 *
 *   - When AI is disabled by configuration, `generate()` throws
 *     {@see ProviderError} immediately and makes no network call at all.
 *   - The request asks for strict JSON output and sends only the
 *     pseudonymised prompt built by {@see PromptBuilder} - never an account
 *     identifier, email address or name (Requirement 6.1).
 *   - One retry: a failed attempt (network error, non-2xx, or a body that is
 *     not valid JSON) is tried exactly once more before giving up.
 *   - Every failure path - after the retry is exhausted, or a malformed
 *     response on the last attempt - raises {@see ProviderError}. Nothing
 *     downstream needs to distinguish a timeout from a bad response; both are
 *     "the provider did not deliver a usable recommendation" (Requirement
 *     6.5 is handled one layer up, by AI_Feedback_Service).
 */
final class HttpsFeedbackProvider implements FeedbackProvider
{
    public function __construct(
        private readonly HttpTransport $transport,
        private readonly AiConfig $config,
        private readonly PromptBuilder $promptBuilder = new PromptBuilder(),
    ) {
    }

    public function generate(EntryContent $content): CbtRecommendation
    {
        if (!$this->config->enabled()) {
            throw new ProviderError('AI feedback is disabled by configuration.');
        }

        $requestBody = $this->buildRequestBody($content);
        $headers = [
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $this->config->apiKey(),
        ];

        // One retry: attempt 0 is the first try, attempt 1 is the single retry.
        $attempts = 2;
        $lastFailure = null;

        for ($attempt = 0; $attempt < $attempts; $attempt++) {
            try {
                $response = $this->transport->post(
                    $this->config->endpoint(),
                    $headers,
                    $requestBody,
                    $this->config->timeoutSeconds(),
                );

                return $this->parseRecommendation($response);
            } catch (HttpTransportException|ProviderError $exception) {
                $lastFailure = $exception;
            }
        }

        throw new ProviderError(
            'The AI provider did not return a usable recommendation after retrying.',
            0,
            $lastFailure
        );
    }

    private function buildRequestBody(EntryContent $content): string
    {
        $payload = [
            'model' => $this->config->model(),
            'response_format' => ['type' => 'json_object'],
            'messages' => [
                ['role' => 'system', 'content' => $this->promptBuilder->systemPrompt()],
                ['role' => 'user', 'content' => $this->promptBuilder->userPrompt($content)],
            ],
        ];

        return json_encode($payload, JSON_THROW_ON_ERROR);
    }

    /**
     * @throws ProviderError when the response is not a 2xx, or its body is
     *                       not valid strict JSON carrying the two expected
     *                       string fields
     */
    private function parseRecommendation(HttpResponse $response): CbtRecommendation
    {
        if (!$response->isSuccessful()) {
            throw new ProviderError(sprintf(
                'The AI provider responded with HTTP status %d.',
                $response->statusCode()
            ));
        }

        $envelope = $this->decodeJson($response->body());
        $content = $this->messageContent($envelope);
        $recommendation = $this->decodeJson($content);

        $positiveFocus = $recommendation['positive_focus'] ?? null;
        $suggestedChange = $recommendation['suggested_change'] ?? null;

        if (!is_string($positiveFocus) || !is_string($suggestedChange)) {
            throw new ProviderError(
                'The AI provider response did not contain the expected positive_focus and suggested_change fields.'
            );
        }

        return new CbtRecommendation($positiveFocus, $suggestedChange);
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
