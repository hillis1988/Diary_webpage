<?php

declare(strict_types=1);

namespace Diary\Http;

/**
 * An inbound HTTP request, frozen into a value object.
 *
 * The point of this class is that nothing downstream of the front controller
 * reads a superglobal. `$_SERVER`, `$_GET`, `$_POST` and `$_COOKIE` are sampled
 * once in {@see fromGlobals()}; every middleware, the router and every handler see
 * an immutable copy. That is what lets the whole pipeline (TLS guard, security
 * headers, CSRF) be exercised in a unit test with no web server involved.
 *
 * `attributes` is the seam later middleware use to pass what they derived down the
 * chain - the SessionResolver (task 7.2) attaches the SecurityContext, the
 * authorisation middleware (task 8.1) attaches the resolved Operation - without
 * anybody reaching for global state. Every `with*` method returns a new instance,
 * so a middleware cannot mutate the request its caller still holds.
 */
final class Request
{
    /** Methods that may change stored state, and so require a CSRF token. */
    private const STATE_CHANGING_METHODS = ['POST', 'PUT', 'PATCH', 'DELETE'];

    /**
     * @param array<string, string>  $query      query string parameters
     * @param array<string, string>  $form       parsed request body parameters
     * @param array<string, string>  $cookies    request cookies
     * @param array<string, string>  $headers    header name (lower-case) => value
     * @param array<string, mixed>   $attributes values derived by middleware
     */
    private function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly string $queryString,
        public readonly bool $secure,
        private readonly array $query,
        private readonly array $form,
        private readonly array $cookies,
        private readonly array $headers,
        private readonly array $attributes = [],
    ) {
    }

    /**
     * Build a request from explicit parts. Used by tests and by
     * {@see fromGlobals()}; there is no other constructor.
     *
     * @param array<string, string> $query
     * @param array<string, string> $form
     * @param array<string, string> $cookies
     * @param array<string, string> $headers header names are matched case-insensitively
     */
    public static function of(
        string $method,
        string $path,
        bool $secure = true,
        array $query = [],
        array $form = [],
        array $cookies = [],
        array $headers = [],
        string $queryString = '',
    ): self {
        $normalisedHeaders = [];
        foreach ($headers as $name => $value) {
            $normalisedHeaders[strtolower($name)] = $value;
        }

        if ($queryString === '' && $query !== []) {
            $queryString = http_build_query($query);
        }

        return new self(
            strtoupper($method),
            self::normalisePath($path),
            $queryString,
            $secure,
            $query,
            $form,
            $cookies,
            $normalisedHeaders,
        );
    }

    /**
     * Sample the superglobals once, at the top of the front controller.
     *
     * `$trustForwardedProto` decides whether `X-Forwarded-Proto` may say the
     * connection was secure. A client can forge that header, so trusting it turns
     * the TLS guard off for anyone who asks; it is only safe when a proxy in front
     * of the application always overwrites it. Default is not to trust it.
     *
     * @param array<string, mixed> $server
     * @param array<string, mixed> $query
     * @param array<string, mixed> $form
     * @param array<string, mixed> $cookies
     */
    public static function fromGlobals(
        array $server,
        array $query,
        array $form,
        array $cookies,
        bool $trustForwardedProto = false,
    ): self {
        $headers = self::headersFromServer($server);
        $rawUri = is_string($server['REQUEST_URI'] ?? null) ? $server['REQUEST_URI'] : '/';
        $queryString = is_string($server['QUERY_STRING'] ?? null) ? $server['QUERY_STRING'] : '';

        $path = $rawUri;
        $hashPosition = strpos($path, '#');
        if ($hashPosition !== false) {
            $path = substr($path, 0, $hashPosition);
        }
        $questionPosition = strpos($path, '?');
        if ($questionPosition !== false) {
            if ($queryString === '') {
                $queryString = substr($path, $questionPosition + 1);
            }
            $path = substr($path, 0, $questionPosition);
        }

        return new self(
            strtoupper(is_string($server['REQUEST_METHOD'] ?? null) ? $server['REQUEST_METHOD'] : 'GET'),
            self::normalisePath($path),
            $queryString,
            self::isSecureConnection($server, $headers, $trustForwardedProto),
            self::stringMap($query),
            self::stringMap($form),
            self::stringMap($cookies),
            $headers,
        );
    }

    /**
     * True when the request arrived over TLS. Everything else is a plaintext
     * request that must be redirected before a handler runs.
     */
    public function isSecure(): bool
    {
        return $this->secure;
    }

    /**
     * A state-changing method needs a valid CSRF token; a safe one does not.
     * GET and HEAD must stay side-effect free precisely so this holds.
     */
    public function isStateChanging(): bool
    {
        return in_array($this->method, self::STATE_CHANGING_METHODS, true);
    }

    public function queryParam(string $name): ?string
    {
        return $this->query[$name] ?? null;
    }

    public function formParam(string $name): ?string
    {
        return $this->form[$name] ?? null;
    }

    public function cookie(string $name): ?string
    {
        return $this->cookies[$name] ?? null;
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    /** @return array<string, string> */
    public function cookies(): array
    {
        return $this->cookies;
    }

    /** @return array<string, string> */
    public function formParams(): array
    {
        return $this->form;
    }

    /**
     * The path with its query string, which is what an anonymous redirect has to
     * preserve so the user lands where they were heading (Requirement 2.6).
     */
    public function pathWithQuery(): string
    {
        return $this->queryString === '' ? $this->path : $this->path . '?' . $this->queryString;
    }

    public function attribute(string $name, mixed $default = null): mixed
    {
        return $this->attributes[$name] ?? $default;
    }

    public function hasAttribute(string $name): bool
    {
        return array_key_exists($name, $this->attributes);
    }

    /**
     * The seam for later middleware: session resolution attaches the
     * SecurityContext here, authorisation attaches the Operation.
     */
    public function withAttribute(string $name, mixed $value): self
    {
        $attributes = $this->attributes;
        $attributes[$name] = $value;

        return new self(
            $this->method,
            $this->path,
            $this->queryString,
            $this->secure,
            $this->query,
            $this->form,
            $this->cookies,
            $this->headers,
            $attributes,
        );
    }

    /**
     * A leading slash always, no trailing slash except for the root, so route
     * matching does not have to care which form a link used.
     */
    private static function normalisePath(string $path): string
    {
        if ($path === '') {
            return '/';
        }

        if ($path[0] !== '/') {
            $path = '/' . $path;
        }

        $trimmed = rtrim($path, '/');

        return $trimmed === '' ? '/' : $trimmed;
    }

    /**
     * @param array<string, mixed> $server
     * @param array<string, string> $headers
     */
    private static function isSecureConnection(array $server, array $headers, bool $trustForwardedProto): bool
    {
        $https = $server['HTTPS'] ?? null;
        if (is_string($https) && $https !== '' && strtolower($https) !== 'off') {
            return true;
        }

        if ($https === true) {
            return true;
        }

        // A request that reached the TLS port arrived over TLS even if HTTPS is unset.
        $port = $server['SERVER_PORT'] ?? null;
        if ((is_string($port) || is_int($port)) && (int) $port === 443) {
            return true;
        }

        if ($trustForwardedProto) {
            $forwarded = $headers['x-forwarded-proto'] ?? '';
            // A proxy may append to an existing value; the first entry is the client's.
            $first = strtolower(trim(explode(',', $forwarded)[0]));
            if ($first === 'https') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $server
     * @return array<string, string>
     */
    private static function headersFromServer(array $server): array
    {
        $headers = [];
        foreach ($server as $key => $value) {
            if (!is_string($value)) {
                continue;
            }

            if (str_starts_with($key, 'HTTP_')) {
                $name = strtolower(str_replace('_', '-', substr($key, 5)));
                $headers[$name] = $value;
                continue;
            }

            if ($key === 'CONTENT_TYPE' || $key === 'CONTENT_LENGTH') {
                $headers[strtolower(str_replace('_', '-', $key))] = $value;
            }
        }

        return $headers;
    }

    /**
     * @param array<string, mixed> $values
     * @return array<string, string>
     */
    private static function stringMap(array $values): array
    {
        $strings = [];
        foreach ($values as $name => $value) {
            if (is_string($value)) {
                $strings[(string) $name] = $value;
            } elseif (is_int($value) || is_float($value) || is_bool($value)) {
                $strings[(string) $name] = (string) $value;
            }
            // Arrays and nulls are dropped: no handler in this application takes them.
        }

        return $strings;
    }
}
