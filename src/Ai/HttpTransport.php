<?php

declare(strict_types=1);

namespace Diary\Ai;

/**
 * The one thing an AI adapter needs from the network: send a JSON POST, get a
 * status code and body back.
 *
 * This exists as its own interface purely so tests can substitute a fake and
 * never make a real HTTPS call. {@see CurlHttpTransport} is the only
 * production implementation.
 */
interface HttpTransport
{
    /**
     * @param array<string, string> $headers header name => value
     *
     * @throws HttpTransportException on a network-level failure: DNS, TLS,
     *                                 a refused connection, or a timeout
     */
    public function post(string $url, array $headers, string $body, int $timeoutSeconds): HttpResponse;
}
