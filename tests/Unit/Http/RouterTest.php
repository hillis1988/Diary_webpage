<?php

declare(strict_types=1);

namespace Diary\Tests\Unit\Http;

use Diary\Http\Request;
use Diary\Http\Response;
use Diary\Http\Router;
use PHPUnit\Framework\TestCase;

final class RouterTest extends TestCase
{
    public function testLiteralRouteMatchesOnMethodAndPath(): void
    {
        $router = new Router();
        $router->get('/', static fn (): Response => Response::html('home'));
        $router->post('/entries', static fn (): Response => Response::html('created'));

        self::assertSame('home', $router->handle(Request::of('GET', '/'))->body());
        self::assertSame('created', $router->handle(Request::of('POST', '/entries'))->body());
    }

    public function testPlaceholderCapturesOneSegmentOnly(): void
    {
        $router = new Router();
        $router->get('/entries/{date}', static fn (Request $r, array $p): Response => Response::html($p['date']));

        self::assertSame('2024-05-01', $router->handle(Request::of('GET', '/entries/2024-05-01'))->body());
        self::assertSame(404, $router->handle(Request::of('GET', '/entries/2024-05-01/edit'))->status());
    }

    public function testHeadIsServedByTheGetRoute(): void
    {
        $router = new Router();
        $router->get('/', static fn (): Response => Response::html('home'));

        self::assertSame(200, $router->handle(Request::of('HEAD', '/'))->status());
    }

    public function testUnknownPathIsNotFoundWithNoDetailLeaked(): void
    {
        $router = new Router();
        $router->get('/', static fn (): Response => Response::html('home'));

        $response = $router->handle(Request::of('GET', '/admin/secrets'));

        self::assertSame(404, $response->status());
        self::assertStringContainsString(Router::NOT_FOUND_MESSAGE, $response->body());
        self::assertStringNotContainsString('secrets', $response->body());
    }

    public function testKnownPathWithWrongMethodIsMethodNotAllowedAndAdvertisesAllow(): void
    {
        $router = new Router();
        $router->get('/entries/{date}', static fn (): Response => Response::html('entry'));

        $response = $router->handle(Request::of('DELETE', '/entries/2024-05-01'));

        self::assertSame(405, $response->status());
        self::assertSame('GET', $response->header('Allow'));
    }
}
