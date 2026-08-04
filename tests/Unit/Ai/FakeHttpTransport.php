<?php

declare(strict_types=1);

namespace Diary\Tests\Unit\Ai;

use Diary\Ai\HttpResponse;
use Diary\Ai\HttpTransport;
use Diary\Ai\HttpTransportException;

/**
 * A test-only {@see HttpTransport} that never touches the network. Queue up
 * responses or exceptions with {@see self::queue()} / {@see self::queueFailure()}
 * and inspect every call that was made through {@see self::calls()}.
 */
final class FakeHttpTransport implements HttpTransport
{
    /** @var list<HttpResponse|HttpTransportException> */
    private array $queue = [];

    /** @var list<array{url: string, headers: array<string, string>, body: string, timeoutSeconds: int}> */
    private array $calls = [];

    public function queue(HttpResponse $response): self
    {
        $this->queue[] = $response;

        return $this;
    }

    public function queueFailure(HttpTransportException $exception): self
    {
        $this->queue[] = $exception;

        return $this;
    }

    public function post(string $url, array $headers, string $body, int $timeoutSeconds): HttpResponse
    {
        $this->calls[] = ['url' => $url, 'headers' => $headers, 'body' => $body, 'timeoutSeconds' => $timeoutSeconds];

        $next = array_shift($this->queue);

        if ($next === null) {
            throw new HttpTransportException('FakeHttpTransport has no queued response left.');
        }

        if ($next instanceof HttpTransportException) {
            throw $next;
        }

        return $next;
    }

    /**
     * @return list<array{url: string, headers: array<string, string>, body: string, timeoutSeconds: int}>
     */
    public function calls(): array
    {
        return $this->calls;
    }

    public function callCount(): int
    {
        return count($this->calls);
    }
}
