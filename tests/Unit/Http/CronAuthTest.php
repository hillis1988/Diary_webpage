<?php

declare(strict_types=1);

namespace Diary\Tests\Unit\Http;

use Diary\Http\CronAuth;
use Diary\Http\Request;
use PHPUnit\Framework\TestCase;

/**
 * Token authentication for the cron endpoints (Requirements 4.2, 4.5): a
 * missing, empty, or mismatched token is refused; a matching bearer token or
 * query parameter is accepted; the comparison is constant-time.
 */
final class CronAuthTest extends TestCase
{
    private const TOKEN = 'a-very-secret-cron-token';

    public function testAuthorisesAMatchingBearerToken(): void
    {
        $auth = new CronAuth(self::TOKEN);
        $request = Request::of('GET', '/cron/purge', headers: ['Authorization' => 'Bearer ' . self::TOKEN]);

        self::assertTrue($auth->isAuthorised($request));
    }

    public function testAuthorisesAMatchingQueryToken(): void
    {
        $auth = new CronAuth(self::TOKEN);
        $request = Request::of('GET', '/cron/purge', query: ['token' => self::TOKEN]);

        self::assertTrue($auth->isAuthorised($request));
    }

    public function testRefusesAMissingToken(): void
    {
        $auth = new CronAuth(self::TOKEN);
        $request = Request::of('GET', '/cron/purge');

        self::assertFalse($auth->isAuthorised($request));
    }

    public function testRefusesAWrongToken(): void
    {
        $auth = new CronAuth(self::TOKEN);
        $request = Request::of('GET', '/cron/purge', query: ['token' => 'wrong']);

        self::assertFalse($auth->isAuthorised($request));
    }

    public function testRefusesEveryCallerWhenNoTokenIsConfigured(): void
    {
        $auth = new CronAuth('');
        $request = Request::of('GET', '/cron/purge', query: ['token' => 'anything']);

        self::assertFalse($auth->isAuthorised($request));
    }

    public function testUnauthorisedResponseIsA401WithNoTokenDetail(): void
    {
        $auth = new CronAuth(self::TOKEN);

        $response = $auth->unauthorised();

        self::assertSame(401, $response->status());
        self::assertStringNotContainsString(self::TOKEN, $response->body());
    }
}
