<?php

declare(strict_types=1);

namespace Diary\Http;

/**
 * The pass/fail predicates behind `tools/smoke_check_transport.php`.
 *
 * Requirement 4.3 says health data must never cross a plaintext connection and
 * every response must carry the fixed security headers. The script that proves
 * this against a live, deployed host has to make real network calls, which this
 * project's tests never do (see {@see \Diary\Tests\Unit\Http\TransportSecurityTest}
 * for the in-process equivalent). So the network plumbing lives in the script and
 * everything that is a judgement on already-fetched data - is this header value
 * good enough, is this redirect the right shape - lives here instead, as pure
 * functions a unit test can drive with synthetic responses.
 */
final class TransportChecks
{
    /**
     * A floor, not the project's own one-year default: long enough that the
     * `Strict-Transport-Security` pin means something, short of insisting a
     * smaller-but-still-reasonable deployment value is treated as broken.
     */
    private const MINIMUM_HSTS_MAX_AGE_SECONDS = 15_768_000; // ~6 months

    private const ACCEPTABLE_FRAME_OPTIONS = ['DENY', 'SAMEORIGIN'];

    /**
     * The five headers the design fixes on every response, checked against an
     * already-fetched response's headers.
     *
     * @param array<string, string> $headers header name (any case) => value
     *
     * @return list<TransportCheckResult>
     */
    public static function checkSecurityHeaders(array $headers): array
    {
        $lowercased = self::lowercaseKeys($headers);

        return [
            self::checkStrictTransportSecurity($lowercased['strict-transport-security'] ?? null),
            self::checkContentSecurityPolicy($lowercased['content-security-policy'] ?? null),
            self::checkExactHeader(
                'X-Content-Type-Options',
                $lowercased['x-content-type-options'] ?? null,
                SecurityHeaders::X_CONTENT_TYPE_OPTIONS
            ),
            self::checkExactHeader(
                'Referrer-Policy',
                $lowercased['referrer-policy'] ?? null,
                SecurityHeaders::REFERRER_POLICY
            ),
            self::checkXFrameOptions($lowercased['x-frame-options'] ?? null),
        ];
    }

    /**
     * Whether at least TLS 1.2 was negotiated.
     *
     * The script establishes the connection with the TLS floor pinned to 1.2 (curl
     * 7.54+ treats `CURL_SSLVERSION_TLSv1_2` as a minimum, not an exact match; a
     * PHP stream client pins the same floor via `STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT`
     * or newer). With that floor in place a successful handshake is itself the
     * proof: the server could not have completed one by falling back to TLS 1.0 or
     * 1.1, so this function only has to look at whether the connection succeeded
     * and, if not, report why.
     */
    public static function checkTlsVersionNegotiated(bool $connectedWithTls12Floor, ?string $failureReason = null): TransportCheckResult
    {
        if (!$connectedWithTls12Floor) {
            return TransportCheckResult::fail(
                'TLS version',
                $failureReason !== null && $failureReason !== ''
                    ? sprintf('Could not negotiate TLS 1.2 or later: %s', $failureReason)
                    : 'Could not negotiate TLS 1.2 or later.'
            );
        }

        return TransportCheckResult::pass('TLS version', 'Negotiated TLS 1.2 or later.');
    }

    /**
     * A plaintext HTTP request must redirect to an `https://` URL on the same
     * host, not merely somewhere.
     */
    public static function checkHttpRedirectsToHttps(int $status, ?string $location, string $host): TransportCheckResult
    {
        if (!in_array($status, [301, 302, 307, 308], true)) {
            return TransportCheckResult::fail(
                'HTTP to HTTPS redirect',
                sprintf('Expected a redirect status (301/302/307/308), got %d.', $status)
            );
        }

        if ($location === null || $location === '') {
            return TransportCheckResult::fail('HTTP to HTTPS redirect', 'No Location header on the redirect.');
        }

        if (!str_starts_with(strtolower($location), 'https://')) {
            return TransportCheckResult::fail(
                'HTTP to HTTPS redirect',
                sprintf('Location "%s" is not an https:// URL.', $location)
            );
        }

        $targetHost = strtolower((string) (parse_url($location, PHP_URL_HOST) ?? ''));
        if ($targetHost !== strtolower($host)) {
            return TransportCheckResult::fail(
                'HTTP to HTTPS redirect',
                sprintf('Location "%s" does not target the requested host "%s".', $location, $host)
            );
        }

        return TransportCheckResult::pass('HTTP to HTTPS redirect', sprintf('%d -> %s', $status, $location));
    }

    private static function checkStrictTransportSecurity(?string $value): TransportCheckResult
    {
        if ($value === null) {
            return TransportCheckResult::fail('Strict-Transport-Security', 'Header is missing.');
        }

        if (preg_match('/max-age\s*=\s*(\d+)/i', $value, $matches) !== 1) {
            return TransportCheckResult::fail(
                'Strict-Transport-Security',
                sprintf('No max-age directive in "%s".', $value)
            );
        }

        if ((int) $matches[1] < self::MINIMUM_HSTS_MAX_AGE_SECONDS) {
            return TransportCheckResult::fail(
                'Strict-Transport-Security',
                sprintf('max-age=%s is shorter than the ~6 month floor.', $matches[1])
            );
        }

        return TransportCheckResult::pass('Strict-Transport-Security', $value);
    }

    private static function checkContentSecurityPolicy(?string $value): TransportCheckResult
    {
        if ($value === null) {
            return TransportCheckResult::fail('Content-Security-Policy', 'Header is missing.');
        }

        if (stripos($value, 'unsafe-inline') !== false || stripos($value, 'unsafe-eval') !== false) {
            return TransportCheckResult::fail(
                'Content-Security-Policy',
                sprintf('Policy allows inline or eval\'d script: "%s".', $value)
            );
        }

        if (stripos($value, 'default-src') === false && stripos($value, 'script-src') === false) {
            return TransportCheckResult::fail(
                'Content-Security-Policy',
                sprintf('Policy has no default-src or script-src directive: "%s".', $value)
            );
        }

        return TransportCheckResult::pass('Content-Security-Policy', $value);
    }

    private static function checkExactHeader(string $name, ?string $value, string $expected): TransportCheckResult
    {
        if ($value === null) {
            return TransportCheckResult::fail($name, 'Header is missing.');
        }

        if (strcasecmp(trim($value), $expected) !== 0) {
            return TransportCheckResult::fail($name, sprintf('Expected "%s", got "%s".', $expected, $value));
        }

        return TransportCheckResult::pass($name, $value);
    }

    private static function checkXFrameOptions(?string $value): TransportCheckResult
    {
        if ($value === null) {
            return TransportCheckResult::fail('X-Frame-Options', 'Header is missing.');
        }

        $normalised = strtoupper(trim($value));

        if (!in_array($normalised, self::ACCEPTABLE_FRAME_OPTIONS, true)) {
            return TransportCheckResult::fail(
                'X-Frame-Options',
                sprintf('Expected DENY or SAMEORIGIN, got "%s".', $value)
            );
        }

        return TransportCheckResult::pass('X-Frame-Options', $value);
    }

    /**
     * @param array<string, string> $headers
     *
     * @return array<string, string>
     */
    private static function lowercaseKeys(array $headers): array
    {
        $lowercased = [];
        foreach ($headers as $name => $value) {
            $lowercased[strtolower($name)] = $value;
        }

        return $lowercased;
    }

    private function __construct()
    {
    }
}
