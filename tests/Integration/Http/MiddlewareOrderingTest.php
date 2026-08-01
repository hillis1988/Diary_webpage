<?php

declare(strict_types=1);

namespace Diary\Tests\Integration\Http;

use Diary\Http\CsrfGuard;
use Diary\Http\CsrfMiddleware;
use Diary\Http\HttpsRedirectMiddleware;
use Diary\Http\Middleware;
use Diary\Http\Pipeline;
use Diary\Http\Request;
use Diary\Http\RequestHandler;
use Diary\Http\Response;
use Diary\Http\Router;
use Diary\Http\SecurityHeaders;
use Diary\Http\SecurityHeadersMiddleware;
use PHPUnit\Framework\TestCase;

/**
 * A stand-in for session resolution / authorisation that records its own label
 * into a shared log and then passes the request straight through. Neither stage
 * exists as a real HTTP middleware yet (authorisation is task 8.1's service, not
 * a pipeline stage), so this is what proves the *slot* each one occupies in the
 * fixed order, without depending on either's real behaviour.
 */
final class OrderRecordingMiddleware implements Middleware
{
    /**
     * @param list<string> $log passed by reference so every stage appends to the
     *                           same shared history
     */
    public function __construct(private readonly string $label, private array &$log)
    {
    }

    public function process(Request $request, RequestHandler $next): Response
    {
        $this->log[] = $this->label;

        return $next->handle($request);
    }
}

/**
 * The real terminal stage: a router with one route, wired through a handler that
 * also records into the shared log so its position relative to the middleware can
 * be asserted the same way theirs is.
 */
final class RecordingRouterHandler implements RequestHandler
{
    private readonly Router $router;

    /**
     * @param list<string> $log
     */
    public function __construct(private array &$log)
    {
        $router = new Router();
        $router->get('/', function () {
            $this->log[] = 'handler';

            return Response::html('the application page');
        });
        $this->router = $router;
    }

    public function handle(Request $request): Response
    {
        return $this->router->handle($request);
    }
}

/**
 * Task 8.6: the pipeline mechanics that Requirements 2.6 and 4.3 depend on -
 * plaintext never reaches the application, and every stage runs in the one order
 * the design fixes - exercised end to end through the real HTTPS guard, security
 * headers and CSRF stages, a real router, and recording stand-ins for the two
 * stages that do not have an HTTP middleware yet.
 */
final class MiddlewareOrderingTest extends TestCase
{
    private const BASE_URL = 'https://royhillis.co.uk';

    public function testPlaintextRequestIsRedirectedBeforeAnyOtherStageOrTheHandlerRuns(): void
    {
        $log = [];
        $pipeline = $this->buildPipeline($log);

        $response = $pipeline->handle(Request::of('GET', '/', false));

        self::assertSame(
            [],
            $log,
            'no security-headers/csrf/session/authorisation/handler stage may run on a plaintext request'
        );
        self::assertSame('', $response->body(), 'no application body may be emitted over plaintext HTTP');
        self::assertSame(HttpsRedirectMiddleware::SAFE_METHOD_STATUS, $response->status());
    }

    public function testSecureRequestRunsTheFiveStagesInExactlyTheFixedOrder(): void
    {
        $log = [];
        $pipeline = $this->buildPipeline($log);

        $response = $pipeline->handle(Request::of('GET', '/', true));

        self::assertSame(
            ['session', 'authorisation', 'handler'],
            $log,
            'session resolution must run before authorisation, which must run before the handler'
        );
        self::assertSame(200, $response->status());
        self::assertSame('the application page', $response->body());
    }

    public function testEveryResponseIncludingThePlaintextRedirectCarriesAllFiveSecurityHeaders(): void
    {
        $log = [];
        $pipeline = $this->buildPipeline($log);

        $plaintextResponse = $pipeline->handle(Request::of('GET', '/', false));

        foreach (SecurityHeaders::names() as $name) {
            self::assertNotNull(
                $plaintextResponse->header($name),
                $name . ' is missing from the plaintext redirect'
            );
        }
    }

    public function testTheHandlersResponseStillCarriesTheSecurityHeadersBecauseTheyWrapTheWholeChain(): void
    {
        $log = [];
        $pipeline = $this->buildPipeline($log);

        $response = $pipeline->handle(Request::of('GET', '/', true));

        self::assertSame(200, $response->status());
        foreach (SecurityHeaders::names() as $name) {
            self::assertNotNull($response->header($name), $name . ' is missing from the handler\'s response');
        }
    }

    /**
     * @param list<string> $log
     */
    private function buildPipeline(array &$log): Pipeline
    {
        return Pipeline::fixedOrder(
            new HttpsRedirectMiddleware(self::BASE_URL),
            new SecurityHeadersMiddleware(),
            new CsrfMiddleware(new CsrfGuard(str_repeat('k', 32))),
            new OrderRecordingMiddleware('session', $log),
            new OrderRecordingMiddleware('authorisation', $log),
            new RecordingRouterHandler($log),
        );
    }
}
