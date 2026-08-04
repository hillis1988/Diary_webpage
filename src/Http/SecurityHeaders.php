<?php

declare(strict_types=1);

namespace Diary\Http;

/**
 * The single source of truth for the response headers the design fixes in its
 * transport security section. Two callers use it - the security headers middleware
 * and the TLS guard, which has to put them on its redirect because that redirect
 * short-circuits the rest of the chain - and neither one owns the values.
 *
 * Each header earns its place:
 *
 * - `Strict-Transport-Security`: after the first HTTPS response the browser refuses
 *   plaintext for a year, so the redirect stops being a window of exposure.
 * - `Content-Security-Policy`: everything is `'self'`. No inline script, so an
 *   injected `<script>` or `onclick` will not run even if some template escapes
 *   badly; no third-party origins, so health data cannot be exfiltrated to another
 *   host by an injected image or fetch. `frame-ancestors 'none'` and
 *   `X-Frame-Options` say the same thing to new and old browsers.
 * - `X-Content-Type-Options: nosniff`: a stored answer must never be sniffed into
 *   being treated as script.
 * - `Referrer-Policy: no-referrer`: a URL can name a date; no other site is told it.
 */
final class SecurityHeaders
{
    /**
     * One year, subdomains included. Deliberately no `preload`: that is a
     * submission to a browser-vendor list and is far harder to undo than a header.
     */
    public const STRICT_TRANSPORT_SECURITY = 'max-age=31536000; includeSubDomains';

    /**
     * `'self'` for every fetch directive, so there is no third-party origin and no
     * inline or `eval`'d script anywhere. `public/assets/app.js` is a real file for
     * exactly this reason.
     */
    public const CONTENT_SECURITY_POLICY = "default-src 'self'; "
        . "script-src 'self'; "
        . "style-src 'self'; "
        . "img-src 'self'; "
        . "font-src 'self'; "
        . "connect-src 'self'; "
        . "form-action 'self'; "
        . "frame-ancestors 'none'; "
        . "base-uri 'none'; "
        . "object-src 'none'";

    public const X_CONTENT_TYPE_OPTIONS = 'nosniff';

    public const REFERRER_POLICY = 'no-referrer';

    public const X_FRAME_OPTIONS = 'DENY';

    private function __construct()
    {
    }

    /**
     * @return array<string, string>
     */
    public static function all(): array
    {
        return [
            'Strict-Transport-Security' => self::STRICT_TRANSPORT_SECURITY,
            'Content-Security-Policy' => self::CONTENT_SECURITY_POLICY,
            'X-Content-Type-Options' => self::X_CONTENT_TYPE_OPTIONS,
            'Referrer-Policy' => self::REFERRER_POLICY,
            'X-Frame-Options' => self::X_FRAME_OPTIONS,
        ];
    }

    /**
     * Header names any response is required to carry, for assertions.
     *
     * @return list<string>
     */
    public static function names(): array
    {
        return array_keys(self::all());
    }

    /**
     * Set every header, overwriting whatever a handler may have put there: a
     * controller does not get to weaken the policy for its own page.
     */
    public static function applyTo(Response $response): Response
    {
        return $response->withHeaders(self::all());
    }
}
