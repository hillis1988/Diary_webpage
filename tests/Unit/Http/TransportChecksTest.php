<?php

declare(strict_types=1);

namespace Diary\Tests\Unit\Http;

use Diary\Http\TransportChecks;
use PHPUnit\Framework\TestCase;

/**
 * Requirement 4.3: the pure pass/fail logic behind `tools/smoke_check_transport.php`,
 * exercised here with synthetic headers rather than a real network call - the script
 * itself is a manual post-deploy gate, not part of this suite.
 */
final class TransportChecksTest extends TestCase
{
    public function testAllFiveHeadersPassWhenTheyMatchTheApplicationDefaults(): void
    {
        $results = TransportChecks::checkSecurityHeaders([
            'Strict-Transport-Security' => 'max-age=31536000; includeSubDomains',
            'Content-Security-Policy' => "default-src 'self'; script-src 'self'; frame-ancestors 'none'",
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'no-referrer',
            'X-Frame-Options' => 'DENY',
        ]);

        self::assertCount(5, $results);
        foreach ($results as $result) {
            self::assertTrue($result->passed(), sprintf('%s: %s', $result->name(), $result->detail()));
        }
    }

    public function testEachMissingHeaderFails(): void
    {
        $results = TransportChecks::checkSecurityHeaders([]);

        self::assertCount(5, $results);
        foreach ($results as $result) {
            self::assertFalse($result->passed());
            self::assertStringContainsString('missing', $result->detail());
        }
    }

    public function testHeaderLookupIsCaseInsensitive(): void
    {
        $results = TransportChecks::checkSecurityHeaders([
            'strict-transport-security' => 'max-age=31536000; includeSubDomains',
            'content-security-policy' => "default-src 'self'",
            'x-content-type-options' => 'nosniff',
            'referrer-policy' => 'no-referrer',
            'x-frame-options' => 'deny',
        ]);

        foreach ($results as $result) {
            self::assertTrue($result->passed(), sprintf('%s: %s', $result->name(), $result->detail()));
        }
    }

    public function testShortHstsMaxAgeFails(): void
    {
        $results = TransportChecks::checkSecurityHeaders([
            'Strict-Transport-Security' => 'max-age=60',
            'Content-Security-Policy' => "default-src 'self'",
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'no-referrer',
            'X-Frame-Options' => 'DENY',
        ]);

        $hsts = self::findResult($results, 'Strict-Transport-Security');
        self::assertFalse($hsts->passed());
    }

    public function testContentSecurityPolicyAllowingInlineScriptFails(): void
    {
        $results = TransportChecks::checkSecurityHeaders([
            'Strict-Transport-Security' => 'max-age=31536000; includeSubDomains',
            'Content-Security-Policy' => "default-src 'self'; script-src 'self' 'unsafe-inline'",
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'no-referrer',
            'X-Frame-Options' => 'DENY',
        ]);

        $csp = self::findResult($results, 'Content-Security-Policy');
        self::assertFalse($csp->passed());
    }

    public function testSameoriginFrameOptionsIsAcceptedButAnythingElseFails(): void
    {
        $accepted = TransportChecks::checkSecurityHeaders(self::validHeaders(['X-Frame-Options' => 'SAMEORIGIN']));
        self::assertTrue(self::findResult($accepted, 'X-Frame-Options')->passed());

        $rejected = TransportChecks::checkSecurityHeaders(self::validHeaders(['X-Frame-Options' => 'ALLOW-FROM https://example.com']));
        self::assertFalse(self::findResult($rejected, 'X-Frame-Options')->passed());
    }

    public function testTlsVersionCheckPassesOnlyWhenTheFloorConnectionSucceeded(): void
    {
        self::assertTrue(TransportChecks::checkTlsVersionNegotiated(true)->passed());

        $failure = TransportChecks::checkTlsVersionNegotiated(false, 'handshake failure');
        self::assertFalse($failure->passed());
        self::assertStringContainsString('handshake failure', $failure->detail());
    }

    public function testHttpRedirectsToHttpsAcceptsAMatchingRedirect(): void
    {
        $result = TransportChecks::checkHttpRedirectsToHttps(301, 'https://royhillis.co.uk/', 'royhillis.co.uk');

        self::assertTrue($result->passed());
    }

    public function testHttpRedirectFailsOnANonRedirectStatus(): void
    {
        $result = TransportChecks::checkHttpRedirectsToHttps(200, null, 'royhillis.co.uk');

        self::assertFalse($result->passed());
    }

    public function testHttpRedirectFailsWhenLocationIsNotHttps(): void
    {
        $result = TransportChecks::checkHttpRedirectsToHttps(301, 'http://royhillis.co.uk/', 'royhillis.co.uk');

        self::assertFalse($result->passed());
    }

    public function testHttpRedirectFailsWhenLocationTargetsADifferentHost(): void
    {
        $result = TransportChecks::checkHttpRedirectsToHttps(301, 'https://attacker.example/', 'royhillis.co.uk');

        self::assertFalse($result->passed());
    }

    /**
     * @param array<string, string> $overrides
     *
     * @return array<string, string>
     */
    private static function validHeaders(array $overrides = []): array
    {
        return array_merge([
            'Strict-Transport-Security' => 'max-age=31536000; includeSubDomains',
            'Content-Security-Policy' => "default-src 'self'",
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'no-referrer',
            'X-Frame-Options' => 'DENY',
        ], $overrides);
    }

    /**
     * @param list<\Diary\Http\TransportCheckResult> $results
     */
    private static function findResult(array $results, string $name): \Diary\Http\TransportCheckResult
    {
        foreach ($results as $result) {
            if ($result->name() === $name) {
                return $result;
            }
        }

        self::fail(sprintf('No check result named "%s".', $name));
    }
}
