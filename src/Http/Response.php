<?php

declare(strict_types=1);

namespace Diary\Http;

use InvalidArgumentException;

/**
 * An outbound response, built as a value object and only emitted at the very end.
 *
 * Nothing in the application calls `header()` or `echo` directly. A handler or a
 * middleware returns one of these, middleware further out may add headers to it,
 * and the front controller calls {@see send()} once. Two things fall out of that:
 *
 * - the security headers can be asserted in a unit test, because building the
 *   response is pure code (`status()`, `headers()`, `body()`);
 * - a middleware such as the TLS guard can return a response *instead of* calling
 *   the next stage, so no application body is ever emitted over plaintext HTTP.
 *
 * Instances are immutable; `with*` returns a copy.
 */
final class Response
{
    public const HTML_CONTENT_TYPE = 'text/html; charset=utf-8';

    /**
     * @param array<string, string> $headers header name => value, name kept as given
     */
    private function __construct(
        private readonly int $status,
        private readonly array $headers,
        private readonly string $body,
    ) {
        if ($status < 100 || $status > 599) {
            throw new InvalidArgumentException(sprintf('Not an HTTP status code: %d', $status));
        }
    }

    /**
     * @param array<string, string> $headers
     */
    public static function html(string $body, int $status = 200, array $headers = []): self
    {
        return new self($status, array_merge(['Content-Type' => self::HTML_CONTENT_TYPE], $headers), $body);
    }

    /**
     * A redirect carries no body. That is not cosmetic: the TLS guard redirects a
     * plaintext request, and an empty body is the guarantee that nothing from the
     * application travelled in the clear.
     *
     * @param array<string, string> $headers
     */
    public static function redirect(string $location, int $status = 302, array $headers = []): self
    {
        if ($location === '') {
            throw new InvalidArgumentException('A redirect needs a Location.');
        }

        if ($status < 300 || $status > 399) {
            throw new InvalidArgumentException(sprintf('Not a redirect status code: %d', $status));
        }

        return new self($status, array_merge(['Location' => $location], $headers), '');
    }

    /**
     * @param array<string, string> $headers
     */
    public static function noContent(int $status = 204, array $headers = []): self
    {
        return new self($status, $headers, '');
    }

    public function status(): int
    {
        return $this->status;
    }

    /** @return array<string, string> */
    public function headers(): array
    {
        return $this->headers;
    }

    public function body(): string
    {
        return $this->body;
    }

    /**
     * Header lookup is case-insensitive, as HTTP header names are.
     */
    public function header(string $name): ?string
    {
        foreach ($this->headers as $headerName => $value) {
            if (strcasecmp($headerName, $name) === 0) {
                return $value;
            }
        }

        return null;
    }

    public function hasHeader(string $name): bool
    {
        return $this->header($name) !== null;
    }

    /**
     * Replaces any existing header of the same name, whatever its casing, so a
     * middleware cannot end up emitting a security header twice with two values.
     */
    public function withHeader(string $name, string $value): self
    {
        $headers = [];
        foreach ($this->headers as $headerName => $headerValue) {
            if (strcasecmp($headerName, $name) !== 0) {
                $headers[$headerName] = $headerValue;
            }
        }
        $headers[$name] = $value;

        return new self($this->status, $headers, $this->body);
    }

    /**
     * @param array<string, string> $headers
     */
    public function withHeaders(array $headers): self
    {
        $response = $this;
        foreach ($headers as $name => $value) {
            $response = $response->withHeader($name, $value);
        }

        return $response;
    }

    public function withStatus(int $status): self
    {
        return new self($status, $this->headers, $this->body);
    }

    /**
     * Emit the response. The only impure method on the class, and the only place
     * in the application that writes to the output stream.
     */
    public function send(): void
    {
        if (!headers_sent()) {
            http_response_code($this->status);
            foreach ($this->headers as $name => $value) {
                header($name . ': ' . $value, true);
            }
        }

        echo $this->body;
    }
}
