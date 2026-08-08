<?php

declare(strict_types=1);

namespace Diary\Ai;

use JsonException;

/**
 * HTTPS dietitian summary provider. Mirrors {@see HttpsSummaryProvider}: one
 * retry, strict JSON, same transport and config, separate prompt and shape.
 */
final class HttpsDietSummaryProvider implements DietSummaryProvider
{
    public function __construct(
        private readonly HttpTransport $transport,
        private readonly AiConfig $config,
        private readonly DietSummaryPromptBuilder $promptBuilder = new DietSummaryPromptBuilder(),
    ) {
    }

    public function generate(DietSummaryInput $input): DietSummary
    {
        if (!$this->config->enabled()) {
            throw new ProviderError('AI diet summary is disabled by configuration.');
        }

        $payload = $this->promptBuilder->buildPayload($input);
        $headers = [
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $this->config->apiKey(),
        ];

        $attempts = 2;
        $lastFailure = null;

        for ($attempt = 0; $attempt < $attempts; $attempt++) {
            try {
                $response = $this->transport->post(
                    $this->config->endpoint(),
                    $headers,
                    $this->buildRequestBody($payload),
                    $this->config->timeoutSeconds(),
                );

                return $this->parseSummary($response);
            } catch (JsonException|HttpTransportException|ProviderError $exception) {
                $lastFailure = $exception;
            }
        }

        throw new ProviderError(
            'The AI provider did not return a usable diet summary after retrying.',
            0,
            $lastFailure
        );
    }

    private function buildRequestBody(DietSummaryPayload $payload): string
    {
        $body = [
            'model' => $this->config->model(),
            'response_format' => ['type' => 'json_object'],
            'messages' => [
                ['role' => 'system', 'content' => $this->promptBuilder->systemPrompt()],
                ['role' => 'user', 'content' => json_encode($payload, JSON_THROW_ON_ERROR)],
            ],
        ];

        return json_encode($body, JSON_THROW_ON_ERROR);
    }

    private function parseSummary(HttpResponse $response): DietSummary
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

        $fields = [];
        foreach (['overview', 'patterns', 'mood_links', 'suggestion'] as $key) {
            $value = $decoded[$key] ?? null;

            if (!is_string($value) || $value === '') {
                throw new ProviderError(
                    'The AI provider response did not contain a valid ' . $key . ' field.'
                );
            }

            $fields[$key] = $value;
        }

        return new DietSummary(
            $fields['overview'],
            $fields['patterns'],
            $fields['mood_links'],
            $fields['suggestion'],
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
