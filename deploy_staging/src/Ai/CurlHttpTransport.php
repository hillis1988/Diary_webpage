<?php

declare(strict_types=1);

namespace Diary\Ai;

/**
 * The only production {@see HttpTransport}: a thin wrapper over PHP's curl
 * extension. Deliberately minimal - one POST method, no retry or JSON logic
 * of its own - because retrying and interpreting the response body are
 * {@see HttpsFeedbackProvider}'s job, not the transport's.
 */
final class CurlHttpTransport implements HttpTransport
{
    public function post(string $url, array $headers, string $body, int $timeoutSeconds): HttpResponse
    {
        $handle = curl_init();

        if ($handle === false) {
            throw new HttpTransportException('Could not initialise the HTTPS client.');
        }

        $headerLines = [];
        foreach ($headers as $name => $value) {
            $headerLines[] = sprintf('%s: %s', $name, $value);
        }

        curl_setopt_array($handle, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeoutSeconds,
            CURLOPT_CONNECTTIMEOUT => $timeoutSeconds,
            // The endpoint must be HTTPS (Requirement 4.3); this is not a
            // substitute for that check, only a refusal to talk plaintext.
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $responseBody = curl_exec($handle);
        $errorNumber = curl_errno($handle);
        $errorMessage = curl_error($handle);
        $statusCode = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
        curl_close($handle);

        if ($responseBody === false || $errorNumber !== 0) {
            throw new HttpTransportException(sprintf(
                'The AI provider request failed: %s',
                $errorMessage !== '' ? $errorMessage : 'unknown transport error'
            ));
        }

        return new HttpResponse($statusCode, (string) $responseBody);
    }
}
