<?php

declare(strict_types=1);

namespace Diary\Tests\Unit\Http;

use Diary\Auth\SessionCookie;
use Diary\Http\CsrfGuard;
use Diary\Http\CsrfMiddleware;
use Diary\Http\CsrfVerdict;
use Diary\Http\Request;
use Diary\Http\RequestHandler;
use Diary\Http\Response;
use Diary\Support\FixedClock;
use PHPUnit\Framework\TestCase;

/**
 * A missing or stale CSRF token is rejected with the form-expired message and no write.
 * "No write" is asserted the only way that is conclusive at this level: the stage beneath the
 * check is never reached, so nothing downstream had the chance to touch the database.
 */
final class CsrfTest extends TestCase
{
    private const SESSION = 'a-session-token-value';

    public function testAFreshlyIssuedTokenVerifies(): void
    {
        $clock = FixedClock::at('2024-05-01 09:00:00');
        $guard = new CsrfGuard(str_repeat('s', 32), $clock);

        $token = $guard->issueFor($this->page());

        self::assertSame(CsrfVerdict::Valid, $guard->verify($this->submission($token)));
    }

    public function testATokenIsAcceptedInTheHeaderAsWellAsTheFormField(): void
    {
        $clock = FixedClock::at('2024-05-01 09:00:00');
        $guard = new CsrfGuard(str_repeat('s', 32), $clock);
        $token = $guard->issueFor($this->page());

        $request = Request::of(
            'POST',
            '/entries',
            true,
            [],
            [],
            [SessionCookie::NAME => self::SESSION],
            [CsrfGuard::HEADER_NAME => $token],
        );

        self::assertSame(CsrfVerdict::Valid, $guard->verify($request));
    }

    public function testTokenIsStaleOnceItsLifetimeHasPassed(): void
    {
        $clock = FixedClock::at('2024-05-01 09:00:00');
        $guard = new CsrfGuard(str_repeat('s', 32), $clock);
        $token = $guard->issueFor($this->page());

        $clock->advanceMinutes(CsrfGuard::LIFETIME_MINUTES);
        self::assertSame(CsrfVerdict::Valid, $guard->verify($this->submission($token)), 'the boundary is inclusive');

        $clock->advanceSeconds(1);
        self::assertSame(CsrfVerdict::Stale, $guard->verify($this->submission($token)));
    }

    public function testMissingMalformedAndForgedTokensAreEachRefused(): void
    {
        $clock = FixedClock::at('2024-05-01 09:00:00');
        $guard = new CsrfGuard(str_repeat('s', 32), $clock);
        $token = $guard->issueFor($this->page());

        self::assertSame(CsrfVerdict::Missing, $guard->verify($this->submission(null)));
        self::assertSame(CsrfVerdict::Missing, $guard->verify($this->submission('')));
        self::assertSame(CsrfVerdict::Malformed, $guard->verify($this->submission('not-a-token')));
        self::assertSame(CsrfVerdict::Malformed, $guard->verify($this->submission('v1.notanumber.abc')));
        self::assertSame(CsrfVerdict::Mismatched, $guard->verify($this->submission($token . '0')));

        // Same shape and time, different signature: the timestamp is only trusted after the MAC.
        [, $issuedAt] = explode('.', $token);
        $forged = 'v1.' . $issuedAt . '.' . str_repeat('0', 64);
        self::assertSame(CsrfVerdict::Mismatched, $guard->verify($this->submission($forged)));
    }

    public function testATokenIssuedForAnotherSessionDoesNotVerify(): void
    {
        $clock = FixedClock::at('2024-05-01 09:00:00');
        $guard = new CsrfGuard(str_repeat('s', 32), $clock);
        $token = $guard->issueFor($this->page());

        $otherSession = Request::of(
            'POST',
            '/entries',
            true,
            [],
            [CsrfGuard::FIELD_NAME => $token],
            [SessionCookie::NAME => 'a-different-session-token'],
        );

        self::assertSame(CsrfVerdict::Mismatched, $guard->verify($otherSession));
    }

    public function testASecretDerivedFromTheMasterKeyIsNotTheMasterKey(): void
    {
        $masterKey = random_bytes(32);
        $clock = FixedClock::at('2024-05-01 09:00:00');

        $derived = CsrfGuard::withMasterKey($masterKey, $clock);
        $usingMasterKeyDirectly = new CsrfGuard($masterKey, $clock);

        $token = $derived->issueFor($this->page());

        self::assertSame(CsrfVerdict::Valid, $derived->verify($this->submission($token)));
        self::assertSame(CsrfVerdict::Mismatched, $usingMasterKeyDirectly->verify($this->submission($token)));
    }

    public function testStateChangingRequestWithoutATokenGetsTheFormExpiredMessageAndNoHandlerRuns(): void
    {
        $clock = FixedClock::at('2024-05-01 09:00:00');
        $middleware = new CsrfMiddleware(new CsrfGuard(str_repeat('s', 32), $clock));
        $handler = $this->spyHandler();

        $response = $middleware->process($this->submission(null), $handler);

        self::assertSame(CsrfMiddleware::REJECTED_STATUS, $response->status());
        self::assertStringContainsString(CsrfMiddleware::FORM_EXPIRED_MESSAGE, $response->body());
        self::assertFalse($handler->reached, 'a rejected submission must never reach a handler, so no write can happen');
    }

    public function testStaleTokenIsRejectedTheSameWay(): void
    {
        $clock = FixedClock::at('2024-05-01 09:00:00');
        $guard = new CsrfGuard(str_repeat('s', 32), $clock);
        $token = $guard->issueFor($this->page());
        $clock->advanceMinutes(CsrfGuard::LIFETIME_MINUTES + 1);

        $handler = $this->spyHandler();
        $response = (new CsrfMiddleware($guard))->process($this->submission($token), $handler);

        self::assertSame(CsrfMiddleware::REJECTED_STATUS, $response->status());
        self::assertStringContainsString(CsrfMiddleware::FORM_EXPIRED_MESSAGE, $response->body());
        self::assertFalse($handler->reached);
    }

    public function testValidTokenPassesThroughAndRecordsTheVerdict(): void
    {
        $clock = FixedClock::at('2024-05-01 09:00:00');
        $guard = new CsrfGuard(str_repeat('s', 32), $clock);
        $token = $guard->issueFor($this->page());

        $handler = new class implements RequestHandler {
            public mixed $verdict = null;

            public function handle(Request $request): Response
            {
                $this->verdict = $request->attribute(CsrfMiddleware::VERDICT_ATTRIBUTE);

                return Response::html('written');
            }
        };

        $response = (new CsrfMiddleware($guard))->process($this->submission($token), $handler);

        self::assertSame('written', $response->body());
        self::assertSame(CsrfVerdict::Valid, $handler->verdict);
    }

    public function testSafeMethodsAreNotChecked(): void
    {
        $middleware = new CsrfMiddleware(new CsrfGuard(str_repeat('s', 32), FixedClock::at('2024-05-01 09:00:00')));

        foreach (['GET', 'HEAD'] as $method) {
            $handler = $this->spyHandler();
            $response = $middleware->process(Request::of($method, '/entries', true), $handler);

            self::assertTrue($handler->reached, $method . ' must not need a token');
            self::assertSame(200, $response->status());
        }
    }

    /**
     * The page render that issues the token: a GET carrying the session cookie.
     */
    private function page(): Request
    {
        return Request::of('GET', '/entries/2024-05-01', true, [], [], [SessionCookie::NAME => self::SESSION]);
    }

    /**
     * The submission that comes back, optionally carrying a token in the form field.
     */
    private function submission(?string $token): Request
    {
        return Request::of(
            'POST',
            '/entries',
            true,
            [],
            $token === null ? [] : [CsrfGuard::FIELD_NAME => $token],
            [SessionCookie::NAME => self::SESSION],
        );
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
