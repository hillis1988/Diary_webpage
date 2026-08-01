<?php

declare(strict_types=1);

namespace Diary\Ai;

/**
 * The bare result of one HTTPS call: a status code and a body. Nothing here
 * assumes any particular provider's wire format; interpreting the body is the
 * caller's job.
 */
final class HttpResponse
{
    public function __construct(
        private readonly int $statusCode,
        private readonly string $body,
    ) {
    }

    public function statusCode(): int
    {
        return $this->statusCode;
    }

    public function isSuccessful(): bool
    {
        return $this->statusCode >= 200 && $this->statusCode < 300;
    }

    public function body(): string
    {
        return $this->body;
    }
}
