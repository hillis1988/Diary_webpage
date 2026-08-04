<?php

declare(strict_types=1);

namespace Diary\Tests\Unit\Auth;

use Diary\Auth\SessionCookie;
use Diary\Auth\SessionToken;
use PHPUnit\Framework\TestCase;

/**
 * The session cookie's attributes, which the design fixes: Secure, HttpOnly,
 * SameSite=Strict, host-only, and no expiry (Requirements 2.4, 2.5, 4.3).
 */
final class SessionCookieTest extends TestCase
{
    public function testAnIssuedCookieIsSecureHttpOnlyStrictHostOnlyAndSessionScoped(): void
    {
        $token = SessionToken::generate();

        $header = SessionCookie::header($token);

        self::assertStringStartsWith(SessionCookie::NAME . '=' . $token->value() . ';', $header);
        self::assertStringContainsString('; Path=/', $header);
        self::assertStringContainsString('; Secure', $header);
        self::assertStringContainsString('; HttpOnly', $header);
        self::assertStringContainsString('; SameSite=Strict', $header);

        // Host-only: no Domain attribute, so the token never reaches a sibling subdomain.
        self::assertStringNotContainsStringIgnoringCase('Domain=', $header);

        // A session cookie: no expiry attribute of either kind, so it is not written to disk.
        self::assertStringNotContainsStringIgnoringCase('Expires=', $header);
        self::assertStringNotContainsStringIgnoringCase('Max-Age=', $header);
    }

    public function testClearingTheCookieExpiresItWhileKeepingTheSameAttributes(): void
    {
        $header = SessionCookie::clearingHeader();

        self::assertStringStartsWith(SessionCookie::NAME . '=;', $header, 'no token value is sent back');
        self::assertStringContainsString('; Secure', $header);
        self::assertStringContainsString('; HttpOnly', $header);
        self::assertStringContainsString('; SameSite=Strict', $header);
        self::assertStringContainsString('; Max-Age=0', $header);
        self::assertStringContainsString('Expires=Thu, 01 Jan 1970 00:00:00 GMT', $header);
    }

    public function testOnlyAWellFormedCookieValueIsReadBackAsAToken(): void
    {
        $token = SessionToken::generate();

        $read = SessionCookie::readToken([SessionCookie::NAME => $token->value()]);
        self::assertNotNull($read);
        self::assertSame($token->value(), $read->value());

        self::assertNull(SessionCookie::readToken([]), 'no cookie is not signed in');
        self::assertNull(SessionCookie::readToken([SessionCookie::NAME => 'ABC']));
        self::assertNull(SessionCookie::readToken([SessionCookie::NAME => strtoupper($token->value())]));
        self::assertNull(SessionCookie::readToken([SessionCookie::NAME => ['array']]));
    }
}
