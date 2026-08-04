<?php

declare(strict_types=1);

namespace Diary\Ai;

use JsonException;

/**
 * HTTPS adapter for Bright Spots reminders. Mirrors {@see HttpsSummaryProvider}
 * but asks for a friend-toned positives payload instead of CBT summary advice.
 */
final class HttpsPositivesProvider implements PositivesProvider
{
    public function __construct(
        private readonly HttpTransport $transport,
        private readonly AiConfig $config,
        private readonly PositivesPromptBuilder $promptBuilder = new PositivesPromptBuilder(),
    ) {
    }

    public function generate(SummaryInput $input): PositivesReminder
    {
        if (!$this->config->enabled()) {
            throw new ProviderError('AI positives are disabled by configuration.');
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

                return $this->parseReminder($response);
            } catch (JsonException|HttpTransportException|ProviderError $exception) {
                $lastFailure = $exception;
            }
        }

        throw new ProviderError(
            'The AI provider did not return usable bright spots after retrying.',
            0,
            $lastFailure
        );
    }

    private function buildRequestBody(SummaryPayload $payload): string
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

    private function parseReminder(HttpResponse $response): PositivesReminder
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

        $greeting = $decoded['greeting'] ?? null;
        $encouragement = $decoded['encouragement'] ?? null;
        $highlightsRaw = $decoded['highlights'] ?? null;

        if (!is_string($greeting) || trim($greeting) === '') {
            throw new ProviderError('The AI provider response did not contain a valid greeting field.');
        }

        if (!is_string($encouragement) || trim($encouragement) === '') {
            throw new ProviderError('The AI provider response did not contain a valid encouragement field.');
        }

        if (!is_array($highlightsRaw) || $highlightsRaw === []) {
            throw new ProviderError('The AI provider response did not contain any highlights.');
        }

        $highlights = [];

        foreach ($highlightsRaw as $item) {
            if (!is_array($item)) {
                throw new ProviderError('The AI provider response contained an invalid highlight.');
            }

            $date = $item['date'] ?? null;
            $title = $item['title'] ?? null;
            $why = $item['why_it_mattered'] ?? null;
            $keepGoing = $item['keep_going'] ?? null;

            if (!is_string($date) || !is_string($title) || !is_string($why) || !is_string($keepGoing)) {
                throw new ProviderError('The AI provider response contained an incomplete highlight.');
            }

            if (trim($date) === '' || trim($title) === '' || trim($why) === '' || trim($keepGoing) === '') {
                throw new ProviderError('The AI provider response contained an empty highlight field.');
            }

            $highlights[] = new PositiveHighlight($date, $title, $why, $keepGoing);
        }

        return new PositivesReminder($greeting, $encouragement, $highlights);
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
