<?php

declare(strict_types=1);

namespace Diary\Tests\Unit\Http;

use Diary\Http\CsrfGuard;
use Diary\Http\CsrfMiddleware;
use Diary\Http\HttpsRedirectMiddleware;
use Diary\Http\Middleware;
use Diary\Http\Pipeline;
use Diary\Http\Request;
use Diary\Http\RequestHandler;
use Diary\Http\Response;
use Diary\Http\SecurityHeaders;
use Diary\Http\SecurityHeadersMiddleware;
use PHPUnit\Framework\TestCase;

/**
 * Records the order stages ran in.
 */
final class StageLog
{
    /** @var list<string> */
    public array $entries = [];

    public function add(string $entry): void
    {
        $this->entries[] = $entry;
    }
}

/**
 * A stage that notes when it was entered and left, and passes the request on.
 */
final class RecordingMiddleware implements Middleware
{
    public function __construct(private readonly string $label, private readonly StageLog $log)
    {
    }

    public function process(Request $request, RequestHandler $next): Response
    {
        $this->log->add($this->label . ':in');
        $response = $next->handle($request);
        $this->log->add($this->label . ':out');

        return $response;
    }
}

final class RecordingHandler implements RequestHandler
{
    public function __construct(private readonly StageLog $log)
    {
    }

    public function handle(Request $request): Response
    {
        $this->log->add('handler');

        return Response::html('page');
    }
}

/**
 * Ordering across the whole pipeline is an integration concern (task 8.6). What is checked
 * here is the mechanism the ordering rests on: stages run outermost first, a stage that does
 * not call `$next` stops everything beneath it, and the fixed-order factory puts the stages
 * where the design says they go.
 */
final class PipelineTest extends TestCase
{
    public function testStagesRunOutermostFirstAndUnwindInReverse(): void
    {
        $log = new StageLog();
        $pipeline = new Pipeline(
            [new RecordingMiddleware('first', $log), new RecordingMiddleware('second', $log)],
            new RecordingHandler($log),
        );

        $pipeline->handle(Request::of('GET', '/'));

        self::assertSame(['first:in', 'second:in', 'handler', 'second:out', 'first:out'], $log->entries);
    }

    public function testAStageThatAnswersItselfNeverReachesTheHandler(): void
    {
        $log = new StageLog();
        $refusing = new class implements Middleware {
            public function process(Request $request, RequestHandler $next): Response
            {
                return Response::html('refused', 403);
            }
        };

        $pipeline = new Pipeline([$refusing, new RecordingMiddleware('inner', $log)], new RecordingHandler($log));
        $response = $pipeline->handle(Request::of('POST', '/entries'));

        self::assertSame(403, $response->status());
        self::assertSame([], $log->entries, 'nothing beneath the refusing stage may run');
    }

    public function testAttributesAddedByAStageAreVisibleFurtherIn(): void
    {
        $adding = new class implements Middleware {
            public function process(Request $request, RequestHandler $next): Response
            {
                return $next->handle($request->withAttribute('stage.marker', 'set'));
            }
        };

        $handler = new class implements RequestHandler {
            public function handle(Request $request): Response
            {
                return Response::html((string) $request->attribute('stage.marker', 'unset'));
            }
        };

        self::assertSame('set', (new Pipeline([$adding], $handler))->handle(Request::of('GET', '/'))->body());
    }

    public function testFixedOrderPutsTheTlsGuardOutsideTheHeadersAndCsrfStages(): void
    {
        $handler = new class implements RequestHandler {
            public bool $reached = false;

            public function handle(Request $request): Response
            {
                $this->reached = true;

                return Response::html('page');
            }
        };

        $pipeline = Pipeline::fixedOrder(
            new HttpsRedirectMiddleware('https://royhillis.co.uk'),
            new SecurityHeadersMiddleware(),
            new CsrfMiddleware(new CsrfGuard(str_repeat('k', 32))),
            null,
            null,
            $handler,
        );

        // Plaintext POST: the TLS guard answers first, so neither the CSRF stage nor the
        // handler is consulted, and the redirect still carries the security headers.
        $response = $pipeline->handle(Request::of('POST', '/entries', false));

        self::assertSame(HttpsRedirectMiddleware::STATE_CHANGING_METHOD_STATUS, $response->status());
        self::assertFalse($handler->reached);
        self::assertSame('', $response->body());
        foreach (SecurityHeaders::names() as $name) {
            self::assertNotNull($response->header($name), $name . ' is missing from the TLS redirect');
        }
    }

    public function testFixedOrderRunsSessionResolutionAndAuthorisationBetweenCsrfAndTheHandler(): void
    {
        $log = new StageLog();

        $pipeline = Pipeline::fixedOrder(
            new HttpsRedirectMiddleware('https://royhillis.co.uk'),
            new SecurityHeadersMiddleware(),
            new CsrfMiddleware(new CsrfGuard(str_repeat('k', 32))),
            new RecordingMiddleware('session', $log),
            new RecordingMiddleware('authorisation', $log),
            new RecordingHandler($log),
        );

        $pipeline->handle(Request::of('GET', '/', true));

        self::assertSame(
            ['session:in', 'authorisation:in', 'handler', 'authorisation:out', 'session:out'],
            $log->entries,
        );
    }
}
