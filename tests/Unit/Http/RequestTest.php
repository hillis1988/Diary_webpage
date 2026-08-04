<?php

declare(strict_types=1);

namespace Diary\Tests\Unit\Http;

use Diary\Http\Request;
use PHPUnit\Framework\TestCase;

/**
 * The request is the only view the pipeline has of the outside world, so the two things
 * worth pinning down are how it decides a connection was secure (the TLS guard depends on
 * it) and how it decides a request is state-changing (the CSRF check depends on it).
 */
final class RequestTest extends TestCase
{
    public function testPathIsNormalisedAndQueryStringSeparated(): void
    {
        $request = Request::fromGlobals(
            ['REQUEST_METHOD' => 'get', 'REQUEST_URI' => '/entries/2024-05-01/?from=calendar', 'HTTPS' => 'on'],
            ['from' => 'calendar'],
            [],
            [],
        );

        self::assertSame('GET', $request->method);
        self::assertSame('/entries/2024-05-01', $request->path);
        self::assertSame('/entries/2024-05-01?from=calendar', $request->pathWithQuery());
        self::assertSame('calendar', $request->queryParam('from'));
    }

    public function testRootPathSurvivesNormalisation(): void
    {
        self::assertSame('/', Request::of('GET', '/')->path);
        self::assertSame('/', Request::of('GET', '')->path);
    }

    public function testHttpsFlagAndTlsPortBothCountAsSecure(): void
    {
        $withFlag = Request::fromGlobals(['REQUEST_URI' => '/', 'HTTPS' => 'on'], [], [], []);
        $withPort = Request::fromGlobals(['REQUEST_URI' => '/', 'SERVER_PORT' => '443'], [], [], []);

        self::assertTrue($withFlag->isSecure());
        self::assertTrue($withPort->isSecure());
    }

    public function testPlaintextRequestIsNotSecureAndForwardedProtoIsIgnoredUnlessTrusted(): void
    {
        $server = ['REQUEST_URI' => '/', 'HTTPS' => 'off', 'SERVER_PORT' => '80', 'HTTP_X_FORWARDED_PROTO' => 'https'];

        // A client can send that header, so believing it by default would let anyone
        // switch the TLS guard off for their own request.
        self::assertFalse(Request::fromGlobals($server, [], [], [])->isSecure());
        self::assertTrue(Request::fromGlobals($server, [], [], [], true)->isSecure());
    }

    public function testOnlyStateChangingMethodsAreFlaggedForCsrf(): void
    {
        foreach (['POST', 'PUT', 'PATCH', 'DELETE'] as $method) {
            self::assertTrue(Request::of($method, '/entries')->isStateChanging(), $method);
        }

        foreach (['GET', 'HEAD', 'OPTIONS'] as $method) {
            self::assertFalse(Request::of($method, '/entries')->isStateChanging(), $method);
        }
    }

    public function testAttributesAreAddedWithoutMutatingTheOriginal(): void
    {
        $request = Request::of('GET', '/');
        $enriched = $request->withAttribute('security.context', 'anonymous');

        self::assertFalse($request->hasAttribute('security.context'));
        self::assertSame('anonymous', $enriched->attribute('security.context'));
        self::assertNull($request->attribute('security.context'));
    }

    public function testHeadersAndCookiesAreReadCaseInsensitivelyWhereHttpSaysTheyShouldBe(): void
    {
        $request = Request::of(
            'POST',
            '/entries',
            true,
            [],
            ['_csrf' => 'token'],
            ['__Host-diary_session' => 'cookie-value'],
            ['X-CSRF-Token' => 'header-token'],
        );

        self::assertSame('token', $request->formParam('_csrf'));
        self::assertSame('header-token', $request->header('x-csrf-token'));
        self::assertSame('cookie-value', $request->cookie('__Host-diary_session'));
        self::assertNull($request->cookie('__host-diary_session'));
    }
}
