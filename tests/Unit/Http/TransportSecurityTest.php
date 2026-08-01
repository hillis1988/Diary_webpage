<?php

declare(strict_types=1);

namespace Diary\Tests\Unit\Http;

use Diary\Http\HttpsRedirectMiddleware;
use Diary\Http\Request;
use Diary\Http\RequestHandler;
use Diary\Http\Response;
use Diary\Http\SecurityHeaders;
use Diary\Http\SecurityHeadersMiddleware;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Requirement 4.3: health data crosses TLS only, and every response says so.
 */
final class TransportSecurityTest extends TestCase
{
    public function testPlaintextGetIsRedirectedToTheCanonicalHttpsUrlWithoutReachingTheHandler(): void
    {
        $handler = $this->spyHandler();
        $response = (new HttpsRedirectMiddleware('https://royhillis.co.uk/'))
            ->process(Request::of('GET', '/entries/2024-05-01', false, ['from' => 'calendar']), $handler);

        self::assertSame(HttpsRedirectMiddleware::SAFE_METHOD_STATUS, $response->status());
        self::assertSame(
            'https://royhillis.co.uk/entries/2024-05-01?from=calendar',
            $response->header('Location')
        );
        self::assertFalse($handler->reached, 'no handler may run on a plaintext connection');
        self::assertSame('', $response->body(), 'no application body may be emitted over plaintext HTTP');
    }

    public function testPlaintextPostKeepsItsMethodOnTheRedirect(): void
    {
        $response = (new HttpsRedirectMiddleware('https://royhillis.co.uk'))
            ->process(Request::of('POST', '/entries', false), $this->spyHandler());

        self::assertSame(HttpsRedirectMiddleware::STATE_CHANGING_METHOD_STATUS, $response->status());
    }

    public function testRedirectTargetIgnoresTheRequestHostSoAForgedHostCannotBounceTheUser(): void
    {
        $response = (new HttpsRedirectMiddleware('https://royhillis.co.uk'))->process(
            Request::of('GET', '/', false, [], [], [], ['Host' => 'attacker.example']),
            $this->spyHandler()
        );

        self::assertSame('https://royhillis.co.uk/', $response->header('Location'));
    }

    public function testRedirectCarriesTheSecurityHeadersBecauseItSkipsTheHeadersStage(): void
    {
        $response = (new HttpsRedirectMiddleware('https://royhillis.co.uk'))
            ->process(Request::of('GET', '/', false), $this->spyHandler());

        self::assertSame(SecurityHeaders::STRICT_TRANSPORT_SECURITY, $response->header('Strict-Transport-Security'));
        self::assertSame(SecurityHeaders::CONTENT_SECURITY_POLICY, $response->header('Content-Security-Policy'));
    }

    public function testSecureRequestPassesStraightThrough(): void
    {
        $handler = $this->spyHandler();
        $response = (new HttpsRedirectMiddleware('https://royhillis.co.uk'))
            ->process(Request::of('GET', '/', true), $handler);

        self::assertTrue($handler->reached);
        self::assertSame(200, $response->status());
    }

    public function testGuardRefusesAPlaintextCanonicalUrlWhenItIsEnabled(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new HttpsRedirectMiddleware('http://royhillis.co.uk');
    }

    /**
     * @return list<array{int}>
     */
    public static function statusCases(): array
    {
        return [[200], [302], [403], [404], [500]];
    }

    #[DataProvider('statusCases')]
    public function testEveryResponseCarriesAllFiveSecurityHeaders(int $status): void
    {
        $handler = new class ($status) implements RequestHandler {
            public function __construct(private readonly int $status)
            {
            }

            public function handle(Request $request): Response
            {
                return $this->status === 302
                    ? Response::redirect('/login', 302)
                    : Response::html('body', $this->status);
            }
        };

        $response = (new SecurityHeadersMiddleware())->process(Request::of('GET', '/', true), $handler);

        self::assertSame($status, $response->status());
        self::assertSame(SecurityHeaders::STRICT_TRANSPORT_SECURITY, $response->header('Strict-Transport-Security'));
        self::assertSame(SecurityHeaders::CONTENT_SECURITY_POLICY, $response->header('Content-Security-Policy'));
        self::assertSame('nosniff', $response->header('X-Content-Type-Options'));
        self::assertSame('no-referrer', $response->header('Referrer-Policy'));
        self::assertSame('DENY', $response->header('X-Frame-Options'));
    }

    public function testHeadersStageOverridesAWeakerPolicySetByAHandler(): void
    {
        $handler = new class implements RequestHandler {
            public function handle(Request $request): Response
            {
                return Response::html('body')->withHeader('X-Frame-Options', 'SAMEORIGIN');
            }
        };

        $response = (new SecurityHeadersMiddleware())->process(Request::of('GET', '/', true), $handler);

        self::assertSame('DENY', $response->header('X-Frame-Options'));
    }

    public function testContentSecurityPolicyAllowsNoInlineScriptAndNoThirdPartyOrigin(): void
    {
        $policy = SecurityHeaders::CONTENT_SECURITY_POLICY;

        self::assertStringContainsString("default-src 'self'", $policy);
        self::assertStringContainsString("script-src 'self'", $policy);
        self::assertStringContainsString("frame-ancestors 'none'", $policy);
        self::assertStringNotContainsString('unsafe-inline', $policy);
        self::assertStringNotContainsString('unsafe-eval', $policy);
        self::assertStringNotContainsString('http://', $policy);
        self::assertStringNotContainsString('https://', $policy);
        self::assertStringNotContainsString('*', $policy);
    }

    private function spyHandler(): RequestHandler
    {
        return new class implements RequestHandler {
            public bool $reached = false;

            public function handle(Request $request): Response
            {
                $this->reached = true;

                return Response::html('page');
            }
        };
    }
}
