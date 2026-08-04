<?php

declare(strict_types=1);

namespace Diary\Http;

/**
 * The shared-secret check every `/cron/*` endpoint runs before doing any
 * work (Requirements 4.2, 4.5).
 *
 * Cron requests carry no session cookie - the IONOS cron manager calls a
 * bare URL - so they cannot be authenticated the way a browser request is.
 * A single shared secret, configured once in `config/config.php` and never
 * logged or rendered, stands in for that. The secret is accepted either as
 * a bearer token (`Authorization: Bearer <token>`) or as a `token` query
 * parameter, since IONOS's cron manager configures a URL rather than
 * request headers.
 *
 * The comparison uses `hash_equals()`, which runs in constant time
 * regardless of where the strings first differ, so a caller cannot narrow
 * down the token one byte at a time by measuring response latency. An
 * empty or missing configured token always fails closed: a misconfigured
 * deployment must refuse every cron call, never accept one.
 */
final class CronAuth
{
    public const MISSING_TOKEN_MESSAGE = 'This endpoint requires a valid cron token.';

    public function __construct(private readonly string $configuredToken)
    {
    }

    /**
     * True only when a configured, non-empty token exactly matches the
     * token the request carried, compared in constant time.
     */
    public function isAuthorised(Request $request): bool
    {
        if ($this->configuredToken === '') {
            return false;
        }

        $supplied = $this->tokenFrom($request);

        if ($supplied === null || $supplied === '') {
            return false;
        }

        return hash_equals($this->configuredToken, $supplied);
    }

    /**
     * A 401 with no body detail: never confirms which check failed, and
     * never echoes back what was supplied.
     */
    public function unauthorised(): Response
    {
        return StatusPage::response(401, 'Unauthorised', self::MISSING_TOKEN_MESSAGE);
    }

    private function tokenFrom(Request $request): ?string
    {
        $header = $request->header('Authorization');

        if (is_string($header) && str_starts_with($header, 'Bearer ')) {
            return substr($header, strlen('Bearer '));
        }

        return $request->queryParam('token');
    }
}
