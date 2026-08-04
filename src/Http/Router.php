<?php

declare(strict_types=1);

namespace Diary\Http;

use InvalidArgumentException;

/**
 * Matches a method and path to a handler.
 *
 * The router is the innermost stage of the pipeline, never the outermost: by the
 * time it runs, TLS has been enforced, the security headers are guaranteed on the
 * way out, and a state-changing request has already proved it carries a fresh token.
 * A route cannot opt out of any of that, because a route is only ever reached
 * through the pipeline.
 *
 * Patterns are literal segments plus `{name}` placeholders, e.g. `/entries/{date}`.
 * That covers every path in this application and keeps matching to a loop over
 * segments - no regular expression compiler, nothing to get subtly wrong. A
 * placeholder never matches across a `/`.
 *
 * Handlers receive the request and the matched parameters:
 *
 *     $router->get('/entries/{date}', fn (Request $r, array $p) => $controller->show($r, $p['date']));
 */
final class Router implements RequestHandler
{
    public const NOT_FOUND_HEADING = 'Page not found';

    public const NOT_FOUND_MESSAGE = 'That page does not exist.';

    public const METHOD_NOT_ALLOWED_HEADING = 'That is not possible here';

    public const METHOD_NOT_ALLOWED_MESSAGE = 'That action cannot be used on this page.';

    /** @var list<array{method: string, segments: list<string>, handler: callable}> */
    private array $routes = [];

    /**
     * @param callable(Request, array<string, string>): Response $handler
     */
    public function add(string $method, string $pattern, callable $handler): void
    {
        if ($pattern === '' || $pattern[0] !== '/') {
            throw new InvalidArgumentException(sprintf('A route pattern must start with "/": "%s"', $pattern));
        }

        $this->routes[] = [
            'method' => strtoupper($method),
            'segments' => self::segments($pattern),
            'handler' => $handler,
        ];
    }

    /**
     * @param callable(Request, array<string, string>): Response $handler
     */
    public function get(string $pattern, callable $handler): void
    {
        $this->add('GET', $pattern, $handler);
    }

    /**
     * @param callable(Request, array<string, string>): Response $handler
     */
    public function post(string $pattern, callable $handler): void
    {
        $this->add('POST', $pattern, $handler);
    }

    public function handle(Request $request): Response
    {
        // HEAD is served by the GET route; the body is dropped when the response is
        // emitted, which keeps every page reachable by a link checker for free.
        $method = $request->method === 'HEAD' ? 'GET' : $request->method;
        $requestSegments = self::segments($request->path);

        $allowed = [];

        foreach ($this->routes as $route) {
            $params = self::match($route['segments'], $requestSegments);
            if ($params === null) {
                continue;
            }

            if ($route['method'] === $method) {
                return ($route['handler'])($request, $params);
            }

            $allowed[$route['method']] = true;
        }

        if ($allowed !== []) {
            // The path exists, the verb does not apply to it.
            return StatusPage::response(405, self::METHOD_NOT_ALLOWED_HEADING, self::METHOD_NOT_ALLOWED_MESSAGE)
                ->withHeader('Allow', implode(', ', array_keys($allowed)));
        }

        return StatusPage::response(404, self::NOT_FOUND_HEADING, self::NOT_FOUND_MESSAGE);
    }

    /**
     * @return list<string>
     */
    private static function segments(string $path): array
    {
        $trimmed = trim($path, '/');

        return $trimmed === '' ? [] : explode('/', $trimmed);
    }

    /**
     * @param list<string> $pattern
     * @param list<string> $actual
     * @return array<string, string>|null the matched parameters, or null when the pattern does not apply
     */
    private static function match(array $pattern, array $actual): ?array
    {
        if (count($pattern) !== count($actual)) {
            return null;
        }

        $params = [];
        foreach ($pattern as $index => $segment) {
            $value = $actual[$index];

            if (strlen($segment) > 2 && $segment[0] === '{' && str_ends_with($segment, '}')) {
                if ($value === '') {
                    return null;
                }
                $params[substr($segment, 1, -1)] = $value;
                continue;
            }

            if ($segment !== $value) {
                return null;
            }
        }

        return $params;
    }
}
