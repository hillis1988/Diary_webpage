<?php

declare(strict_types=1);

namespace Diary\Auth;

/**
 * The one place that knows how the session token travels.
 *
 * The design fixes the attributes (transport security section): `Secure`,
 * `HttpOnly`, `SameSite=Strict`, host-only, and no expiry attribute at all - a
 * session cookie, so closing the browser drops it while the server-side idle
 * timeout stays the real authority.
 *
 * Each of those is load-bearing:
 *
 * - `Secure` keeps the token off a plaintext request;
 * - `HttpOnly` keeps it out of reach of script, so an injected script cannot read it;
 * - `SameSite=Strict` means a cross-site request arrives with no cookie at all,
 *   which is a second line behind the CSRF token;
 * - no `Domain` attribute leaves the cookie host-only, so it is never sent to a
 *   sibling subdomain;
 * - no `Expires` and no `Max-Age` keeps it out of disk storage.
 *
 * {@see header()} and {@see clearingHeader()} are pure functions returning the
 * header value, so the attributes can be asserted in a unit test rather than only
 * observed in a browser. {@see send()} is the thin wrapper that actually emits one.
 */
final class SessionCookie
{
    /**
     * Host-only and prefixed: a `__Host-` cookie is refused by the browser unless
     * it is Secure, path `/`, and carries no Domain, so the name itself enforces
     * two of the attributes below.
     */
    public const NAME = '__Host-diary_session';

    public const PATH = '/';

    private function __construct()
    {
    }

    /**
     * The `Set-Cookie` value that carries a freshly issued token.
     */
    public static function header(SessionToken $token): string
    {
        return self::build($token->value());
    }

    /**
     * The `Set-Cookie` value that removes the cookie, for sign-out and for a session
     * that has timed out (Requirements 2.4, 2.5).
     *
     * Expiry attributes appear only here: an empty value that expired in 1970 is how
     * a cookie is deleted, and it is the one case where an expiry is not a way of
     * storing the token to disk.
     */
    public static function clearingHeader(): string
    {
        return self::build('') . '; Max-Age=0; Expires=Thu, 01 Jan 1970 00:00:00 GMT';
    }

    /**
     * The token a request arrived with, or null when there is none or it is
     * malformed - which is an ordinary "not signed in", not an error.
     *
     * @param array<string, mixed> $cookies typically `$_COOKIE`
     */
    public static function readToken(array $cookies): ?SessionToken
    {
        $value = $cookies[self::NAME] ?? null;

        return is_string($value) ? SessionToken::tryFromString($value) : null;
    }

    /**
     * Emit a header produced above. Kept trivial, and separate from the pure
     * functions, so that everything worth testing is testable without a web server.
     */
    public static function send(string $setCookieHeader): void
    {
        if (headers_sent()) {
            return;
        }

        header('Set-Cookie: ' . $setCookieHeader, false);
    }

    /**
     * No Domain attribute, so the cookie stays host-only.
     */
    private static function build(string $value): string
    {
        return self::NAME . '=' . $value . '; Path=' . self::PATH . '; Secure; HttpOnly; SameSite=Strict';
    }
}
