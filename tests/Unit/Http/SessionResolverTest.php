<?php

declare(strict_types=1);

namespace Diary\Tests\Unit\Http;

use DateTimeImmutable;
use Diary\Auth\AuditLogRepository;
use Diary\Auth\AuthService;
use Diary\Auth\ContextRole;
use Diary\Auth\DefaultPasswordPolicy;
use Diary\Auth\IpHasher;
use Diary\Auth\PasswordHasher;
use Diary\Auth\SecurityContext;
use Diary\Auth\Session;
use Diary\Auth\SessionCookie;
use Diary\Auth\SessionRepository;
use Diary\Auth\SessionToken;
use Diary\Auth\UserRepository;
use Diary\Http\Request;
use Diary\Http\RequestHandler;
use Diary\Http\Response;
use Diary\Http\SessionResolverMiddleware;
use Diary\Support\FixedClock;
use Diary\Tests\Unit\Auth\SqliteAuthTables;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * The handler the stage protects: it records the context it was handed, which is the
 * only thing this stage is supposed to produce.
 */
final class ContextCapturingHandler implements RequestHandler
{
    public ?SecurityContext $context = null;

    public bool $reached = false;

    /** @param array<string, string> $headers headers this handler sets of its own accord */
    public function __construct(private readonly array $headers = [])
    {
    }

    public function handle(Request $request): Response
    {
        $this->reached = true;
        $this->context = SessionResolverMiddleware::contextOf($request);

        return Response::html('page')->withHeaders($this->headers);
    }
}

/**
 * Requirement 2.5: the stage turns a cookie into a SecurityContext, and every case
 * that is not a live session becomes anonymous rather than an error or a gap.
 *
 * Requirement 2.6 - redirecting an anonymous request to the login page - belongs to
 * the authorisation stage (task 8.1); what is checked here is that it always has a
 * context to decide from.
 */
final class SessionResolverTest extends TestCase
{
    private const EMAIL = 'roy@example.com';
    private const PASSWORD = 'correct1horse2battery';

    private PDO $pdo;
    private FixedClock $clock;
    private SessionRepository $sessions;
    private AuthService $auth;
    private SessionResolverMiddleware $middleware;

    protected function setUp(): void
    {
        $this->pdo = SqliteAuthTables::connection();
        $this->clock = FixedClock::at('2025-03-01 09:00:00');
        $this->sessions = new SessionRepository($this->pdo);

        $this->auth = new AuthService(
            new UserRepository($this->pdo),
            new DefaultPasswordPolicy(),
            $this->clock,
            new PasswordHasher(),
            $this->sessions,
            new AuditLogRepository($this->pdo),
            new IpHasher('unit-test-ip-key'),
        );

        self::assertTrue($this->auth->register(self::EMAIL, self::PASSWORD)->isOk());

        $this->middleware = new SessionResolverMiddleware($this->auth, $this->clock);
    }

    public function testALiveSessionArrivesAtTheHandlerAsItsOwnContext(): void
    {
        $token = $this->signIn();
        $handler = new ContextCapturingHandler();

        $response = $this->middleware->process($this->requestWithCookie($token->value()), $handler);

        self::assertTrue($handler->reached);
        self::assertInstanceOf(SecurityContext::class, $handler->context);
        self::assertTrue($handler->context->isAuthenticated());
        self::assertSame(ContextRole::Owner, $handler->context->contextRole);
        self::assertNotNull($handler->context->dataOwnerId, 'an owner context carries its owner scope');
        self::assertFalse(
            $response->hasHeader('Set-Cookie'),
            'a working session is not asked to sign in again'
        );
    }

    public function testNoCookieIsAnonymousAndTheAttributeIsStillThere(): void
    {
        $handler = new ContextCapturingHandler();

        $response = $this->middleware->process(Request::of('GET', '/entries'), $handler);

        self::assertInstanceOf(SecurityContext::class, $handler->context);
        self::assertTrue($handler->context->isAnonymous());
        self::assertNull($handler->context->userId);
        self::assertNull($handler->context->dataOwnerId);
        self::assertFalse(
            $response->hasHeader('Set-Cookie'),
            'there is no cookie to clear for a browser that never had one'
        );
    }

    /**
     * Every way a cookie can fail to name a live session ends in the same place, and
     * in each of them the browser is told to drop the token it is holding.
     */
    public function testEveryDeadTokenIsAnonymousAndClearsTheCookie(): void
    {
        $unknown = SessionToken::generate()->value();

        $signedOut = $this->signIn();
        $this->auth->signOut($signedOut->id(), $this->clock->now());

        $idle = $this->signIn();
        $this->clock->advanceMinutes(AuthService::IDLE_TIMEOUT_MINUTES);

        $cases = [
            'malformed cookie' => 'not-a-session-token',
            'unknown session' => $unknown,
            'signed-out session' => $signedOut->value(),
            'idle session' => $idle->value(),
        ];

        foreach ($cases as $label => $cookie) {
            $handler = new ContextCapturingHandler();
            $response = $this->middleware->process($this->requestWithCookie($cookie), $handler);

            self::assertInstanceOf(SecurityContext::class, $handler->context, $label);
            self::assertTrue($handler->context->isAnonymous(), $label . ' must be anonymous');
            self::assertFalse($handler->context->isOwner(), $label . ' must not be an owner');
            self::assertSame(
                SessionCookie::clearingHeader(),
                $response->header('Set-Cookie'),
                $label . ' should leave the browser without the dead cookie'
            );
        }

        // Requirement 2.5 ends an idle session rather than ignoring it, and the stage
        // resolving it is what does the ending.
        $row = $this->sessions->findById($idle->id());
        self::assertInstanceOf(Session::class, $row);
        self::assertTrue($row->isTerminated(), 'the idle session was terminated, not merely refused');
    }

    public function testAStorageFailureIsAnonymousRatherThanAnException(): void
    {
        $token = $this->signIn();
        $this->pdo->exec('DROP TABLE sessions');

        $handler = new ContextCapturingHandler();
        $response = $this->middleware->process($this->requestWithCookie($token->value()), $handler);

        self::assertInstanceOf(SecurityContext::class, $handler->context);
        self::assertTrue($handler->context->isAnonymous(), 'an unreachable session store fails closed');
        self::assertFalse(
            $response->hasHeader('Set-Cookie'),
            'a database that is down is not evidence that the session ended'
        );
    }

    public function testAHandlerThatSetsItsOwnCookieIsNotOverruled(): void
    {
        $fresh = SessionCookie::header(SessionToken::generate());
        $handler = new ContextCapturingHandler(['Set-Cookie' => $fresh]);

        // A sign-in POST arrives with the dead cookie of the session that just ended and
        // leaves with the cookie of the one just created.
        $response = $this->middleware->process($this->requestWithCookie('not-a-session-token'), $handler);

        self::assertSame($fresh, $response->header('Set-Cookie'));
    }

    public function testAContextIsNeverMissingEvenWhereTheStageDidNotRun(): void
    {
        $context = SessionResolverMiddleware::contextOf(Request::of('GET', '/entries'));

        self::assertTrue($context->isAnonymous());
    }

    private function requestWithCookie(string $value): Request
    {
        return Request::of('GET', '/entries', true, cookies: [SessionCookie::NAME => $value]);
    }

    private function signIn(): SessionToken
    {
        $result = $this->auth->authenticate(self::EMAIL, self::PASSWORD, $this->now());
        self::assertTrue($result->isOk());

        $session = $result->value();
        self::assertInstanceOf(Session::class, $session);

        $token = $session->issuedToken();
        self::assertNotNull($token);

        return $token;
    }

    private function now(): DateTimeImmutable
    {
        return $this->clock->now();
    }
}
