<?php

declare(strict_types=1);

namespace Diary\Tests\Unit\Http;

use Diary\Http\Response;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * The response has to be inspectable without a web server, because that is what makes the
 * security headers assertable at all.
 */
final class ResponseTest extends TestCase
{
    public function testHtmlResponseCarriesStatusHeadersAndBody(): void
    {
        $response = Response::html('<p>hello</p>', 200, ['X-Test' => 'yes']);

        self::assertSame(200, $response->status());
        self::assertSame('<p>hello</p>', $response->body());
        self::assertSame(Response::HTML_CONTENT_TYPE, $response->header('content-type'));
        self::assertSame('yes', $response->header('X-Test'));
    }

    public function testRedirectHasLocationAndNoBody(): void
    {
        $response = Response::redirect('https://royhillis.co.uk/entries', 301);

        self::assertSame(301, $response->status());
        self::assertSame('https://royhillis.co.uk/entries', $response->header('Location'));
        self::assertSame('', $response->body());
    }

    public function testWithHeaderReplacesRegardlessOfCasingSoNoHeaderIsSentTwice(): void
    {
        $response = Response::html('body')->withHeader('X-Frame-Options', 'SAMEORIGIN');
        $tightened = $response->withHeader('x-frame-options', 'DENY');

        self::assertSame('DENY', $tightened->header('X-Frame-Options'));
        self::assertCount(2, $tightened->headers()); // Content-Type plus the one header
    }

    public function testResponsesAreImmutable(): void
    {
        $original = Response::html('body');
        $original->withHeader('X-Test', 'yes')->withStatus(500);

        self::assertSame(200, $original->status());
        self::assertFalse($original->hasHeader('X-Test'));
    }

    public function testRedirectRefusesANonRedirectStatus(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Response::redirect('/somewhere', 200);
    }
}
