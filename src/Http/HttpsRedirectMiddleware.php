<?php

declare(strict_types=1);

namespace Diary\Http;

use InvalidArgumentException;

/**
 * Stage 1: nothing runs on a plaintext connection.
 *
 * `.htaccess` already redirects HTTP to HTTPS. This is the application-level half
 * of the design's "`.htaccess` plus an application-level guard" rule, because a
 * server configuration can be lost in a migration or overridden by a control panel
 * while this file travels with the code. Requirement 4.3 is about health data never
 * crossing an insecure connection, so it deserves two independent enforcements.
 *
 * Being the outermost stage, it returns the redirect without calling `$next`: the
 * router is never consulted, no session is loaded, no handler runs, and the body is
 * empty. Nothing of the application is emitted in the clear.
 *
 * The redirect target is built from the configured canonical base URL, never from
 * the request's `Host` header, so a forged Host cannot bounce a visitor to another
 * site.
 */
final class HttpsRedirectMiddleware implements Middleware
{
    /**
     * A permanent redirect for a request that has no body to lose.
     */
    public const SAFE_METHOD_STATUS = 301;

    /**
     * 308 keeps the method and the body, so a POST does not silently degrade into a
     * GET. The plaintext body has already been exposed - re-sending it over TLS
     * changes nothing about that - but turning a submission into a GET would lose
     * the user's writing, which is the worse failure.
     */
    public const STATE_CHANGING_METHOD_STATUS = 308;

    private readonly string $baseUrl;

    /**
     * @param string $baseUrl canonical HTTPS origin, e.g. https://royhillis.co.uk
     * @param bool   $enabled `app.force_https`; only ever false for local HTTP development
     */
    public function __construct(string $baseUrl, private readonly bool $enabled = true)
    {
        $normalised = rtrim($baseUrl, '/');

        if ($enabled && !str_starts_with(strtolower($normalised), 'https://')) {
            throw new InvalidArgumentException('The canonical base URL must be an https:// origin.');
        }

        $this->baseUrl = $normalised;
    }

    public function process(Request $request, RequestHandler $next): Response
    {
        if ($request->isSecure() || !$this->enabled) {
            return $next->handle($request);
        }

        $status = $request->isStateChanging()
            ? self::STATE_CHANGING_METHOD_STATUS
            : self::SAFE_METHOD_STATUS;

        // The headers go on here too: this response never passes through the
        // security headers stage, and HSTS on the redirect is what stops the next
        // visit from starting in plaintext at all.
        return SecurityHeaders::applyTo(
            Response::redirect($this->baseUrl . $request->pathWithQuery(), $status)
        );
    }
}
