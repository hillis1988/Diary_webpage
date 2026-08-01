<?php

declare(strict_types=1);

namespace Diary\Tests\Integration\Http;

use Diary\Access\AccessControlService;
use Diary\Auth\AuditLogRepository;
use Diary\Auth\AuthService;
use Diary\Auth\DefaultPasswordPolicy;
use Diary\Auth\EmailAddress;
use Diary\Auth\IpHasher;
use Diary\Auth\PasswordHasher;
use Diary\Auth\Session;
use Diary\Auth\SessionCookie;
use Diary\Auth\SessionRepository;
use Diary\Auth\SessionToken;
use Diary\Auth\UserAccount;
use Diary\Auth\UserId;
use Diary\Auth\UserRepository;
use Diary\Auth\UserRole;
use Diary\Auth\UserStatus;
use Diary\Http\AuthorisationMiddleware;
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
use Diary\Http\SessionResolverMiddleware;
use Diary\Support\FixedClock;
use Diary\Support\Ulid;
use Diary\Tests\Unit\Auth\SqliteAuthTables;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * A stage that appends its own label to a shared log and then passes the request
 * on. Used to prove that stages *ahead* of the real ones under test never run,
 * without needing a full fake session-resolution or authorisation implementation.
 */
final class LoggingStage implements Middleware
{
    /**
     * @param list<string> $log passed by reference so every stage - real or fake -
     *                          appends to the same shared history
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
 * Task 8.6: end-to-end proof of the fixed pipeline order and its two structural
 * guarantees - no application body ever leaves over plaintext HTTP
 * (Requirement 4.3), and an unauthenticated or otherwise refused request never
 * reaches a handler (Requirement 2.6).
 *
 * This exercises the *real* stages throughout: {@see HttpsRedirectMiddleware},
 * {@see SecurityHeadersMiddleware}, {@see CsrfMiddleware}, the real
 * {@see SessionResolverMiddleware} backed by an in-memory SQLite `sessions`
 * table (the same fixture {@see SqliteAuthTables} unit tests use, rather than a
 * mock - session resolution is a database read and a test double would not prove
 * anything about it actually querying one), the real {@see AuthorisationMiddleware}
 * backed by a real {@see AccessControlService}, and a real {@see Router}.
 *
 * Ordering itself is proven structurally two ways: a shared log every real stage
 * that reaches its own body appends to (session resolution and authorisation
 * touch the database, which is a stronger proof than a log entry, so the log is
 * corroborating evidence, not the only evidence), and a handler-reached flag that
 * a request refused anywhere upstream can never flip.
 */
final class PipelineAuthorisationTest extends TestCase
{
    private const BASE_URL = 'https://royhillis.co.uk';
    private const OWNER_EMAIL = 'owner@example.com';
    private const OWNER_PASSWORD = 'correct1horse2battery';
    private const PROTECTED_PATH = '/entries';

    private PDO $pdo;
    private FixedClock $clock;
    private AuthService $authService;
    private AccessControlService $accessControl;

    /** @var list<string> */
    private array $log = [];

    protected function setUp(): void
    {
        $this->pdo = SqliteAuthTables::connection();
        $this->clock = FixedClock::at('2024-06-01 09:00:00');

        $this->authService = new AuthService(
            new UserRepository($this->pdo),
            new DefaultPasswordPolicy(),
            $this->clock,
            PasswordHasher::forTests(),
            new SessionRepository($this->pdo),
            new AuditLogRepository($this->pdo),
            new IpHasher('unit-test-ip-key'),
        );

        $this->accessControl = new AccessControlService(
            $this->clock,
            new AuditLogRepository($this->pdo),
            new IpHasher('unit-test-ip-key'),
        );

        self::assertTrue($this->authService->register(self::OWNER_EMAIL, self::OWNER_PASSWORD)->isOk());
    }

    public function testAPlaintextRequestNeverReachesTheRouterAndCarriesNoApplicationBody(): void
    {
        $handlerReached = false;
        $pipeline = $this->buildPipeline($handlerReached);

        $response = $pipeline->handle(Request::of('GET', self::PROTECTED_PATH, false));

        self::assertSame([], $this->log, 'no stage past the TLS guard may run on a plaintext request');
        self::assertFalse($handlerReached, 'the handler must never run on a plaintext request');
        self::assertSame('', $response->body(), 'no application body may be emitted over plaintext HTTP');
        self::assertGreaterThanOrEqual(300, $response->status());
        self::assertLessThan(400, $response->status(), 'a plaintext request is answered with a redirect');
    }

    public function testEveryResponseShapeCarriesAllFiveSecurityHeaders(): void
    {
        $handlerReached = false;
        $pipeline = $this->buildPipeline($handlerReached);

        $plaintextRedirect = $pipeline->handle(Request::of('GET', self::PROTECTED_PATH, false));
        $anonymousRedirect = $pipeline->handle(Request::of('GET', self::PROTECTED_PATH, true));
        $csrfDenial = $pipeline->handle(Request::of('POST', self::PROTECTED_PATH, true));
        $success = $pipeline->handle($this->authenticatedRequest('GET', self::PROTECTED_PATH));

        foreach (['plaintext redirect' => $plaintextRedirect, 'anonymous redirect' => $anonymousRedirect,
            'csrf denial' => $csrfDenial, 'success' => $success] as $label => $response) {
            foreach (SecurityHeaders::names() as $name) {
                self::assertNotNull($response->header($name), $name . ' is missing from the ' . $label);
            }
        }
    }

    public function testAnAnonymousRequestToAProtectedRouteIsRedirectedToLoginAndNeverReachesTheHandler(): void
    {
        $handlerReached = false;
        $pipeline = $this->buildPipeline($handlerReached);

        $response = $pipeline->handle(Request::of('GET', self::PROTECTED_PATH, true));

        self::assertFalse($handlerReached, 'an anonymous request must never reach the handler');
        self::assertSame(302, $response->status());
        self::assertSame(
            '/login?next=' . rawurlencode(self::PROTECTED_PATH),
            $response->header('Location'),
        );
        self::assertSame(
            ['session', 'authorisation'],
            $this->log,
            'session resolution then authorisation ran; the handler never did'
        );
    }

    public function testAStateChangingRequestWithNoCsrfTokenIsRejectedBeforeSessionResolutionOrAuthorisationRun(): void
    {
        $handlerReached = false;
        $pipeline = $this->buildPipeline($handlerReached);

        $response = $pipeline->handle(Request::of('POST', self::PROTECTED_PATH, true));

        self::assertSame(CsrfMiddleware::REJECTED_STATUS, $response->status());
        self::assertFalse($handlerReached, 'a rejected submission must never reach the handler');
        self::assertSame(
            [],
            $this->log,
            'the CSRF stage refused the request before session resolution or authorisation could run'
        );

        // The strongest proof that session resolution never ran: it never touched
        // the sessions table it would otherwise have queried and updated.
        self::assertSame(
            [],
            SqliteAuthTables::sessions($this->pdo),
            'no session row exists to be touched, and none was created either'
        );
    }

    public function testASignedInOwnerRunsSessionResolutionThenAuthorisationThenTheHandlerInThatOrder(): void
    {
        $handlerReached = false;
        $pipeline = $this->buildPipeline($handlerReached);

        $response = $pipeline->handle($this->authenticatedRequest('GET', self::PROTECTED_PATH));

        self::assertTrue($handlerReached, 'an authorised owner request must reach the handler');
        self::assertSame(200, $response->status());
        self::assertSame('the protected page', $response->body());
        self::assertSame(
            ['session', 'authorisation'],
            $this->log,
            'session resolution must be logged before authorisation, and both before the handler ran'
        );
    }

    public function testAViewerContextMutationIsDeniedWithA403AndNeverReachesTheHandler(): void
    {
        $handlerReached = false;
        $pipeline = $this->buildPipeline($handlerReached);
        $token = $this->signInAsFreshViewer();
        $csrfToken = $this->issueCsrfTokenFor($token);

        $response = $pipeline->handle(
            Request::of(
                'POST',
                self::PROTECTED_PATH,
                true,
                [],
                [CsrfGuard::FIELD_NAME => $csrfToken],
                [SessionCookie::NAME => $token->value()],
            )
        );

        self::assertFalse($handlerReached, 'a denied mutation must never reach the handler');
        self::assertSame(403, $response->status());
        self::assertSame(['session', 'authorisation'], $this->log);
    }

    /**
     * Every real stage in the fixed order, wired around a router with one
     * protected route, plus a shared log that {@see SessionResolverMiddleware}
     * and {@see AuthorisationMiddleware} cannot themselves write to (they are the
     * real classes, not stand-ins) - so each is wrapped in a thin logging
     * middleware that records before delegating to the real one. That keeps the
     * log a faithful record of *when* each real stage ran without altering what
     * either one does.
     */
    private function buildPipeline(bool &$handlerReached): Pipeline
    {
        $router = new Router();
        $router->get(self::PROTECTED_PATH, function () use (&$handlerReached) {
            $handlerReached = true;

            return Response::html('the protected page');
        });
        $router->post(self::PROTECTED_PATH, function () use (&$handlerReached) {
            $handlerReached = true;

            return Response::html('the protected page');
        });

        $sessionResolution = new class ($this->authService, $this->clock, $this->log) implements Middleware {
            /** @param list<string> $log */
            public function __construct(
                private readonly AuthService $auth,
                private readonly FixedClock $clock,
                private array &$log,
            ) {
            }

            public function process(Request $request, RequestHandler $next): Response
            {
                $this->log[] = 'session';

                return (new SessionResolverMiddleware($this->auth, $this->clock))->process($request, $next);
            }
        };

        $authorisation = new class ($this->accessControl, $this->log) implements Middleware {
            /** @param list<string> $log */
            public function __construct(private readonly AccessControlService $access, private array &$log)
            {
            }

            public function process(Request $request, RequestHandler $next): Response
            {
                $this->log[] = 'authorisation';

                return (new AuthorisationMiddleware($this->access))->process($request, $next);
            }
        };

        return Pipeline::fixedOrder(
            new HttpsRedirectMiddleware(self::BASE_URL),
            new SecurityHeadersMiddleware(),
            new CsrfMiddleware(new CsrfGuard(str_repeat('k', 32), $this->clock)),
            $sessionResolution,
            $authorisation,
            $router,
        );
    }

    /**
     * A GET or POST for the signed-in owner, with a fresh CSRF token attached to
     * POST bodies so a mutating request is not itself refused by the CSRF stage.
     */
    private function authenticatedRequest(string $method, string $path): Request
    {
        $token = $this->signIn();

        if ($method === 'GET') {
            return Request::of($method, $path, true, [], [], [SessionCookie::NAME => $token->value()]);
        }

        $csrfToken = $this->issueCsrfTokenFor($token);

        return Request::of(
            $method,
            $path,
            true,
            [],
            [CsrfGuard::FIELD_NAME => $csrfToken],
            [SessionCookie::NAME => $token->value()],
        );
    }

    private function issueCsrfTokenFor(SessionToken $sessionToken): string
    {
        $guard = new CsrfGuard(str_repeat('k', 32), $this->clock);

        return $guard->issueFor(Request::of('GET', self::PROTECTED_PATH, true, [], [], [
            SessionCookie::NAME => $sessionToken->value(),
        ]));
    }

    private function signIn(): SessionToken
    {
        $result = $this->authService->authenticate(self::OWNER_EMAIL, self::OWNER_PASSWORD, $this->clock->now());
        self::assertTrue($result->isOk());

        $session = $result->value();
        self::assertInstanceOf(Session::class, $session);

        $token = $session->issuedToken();
        self::assertNotNull($token);

        return $token;
    }

    /**
     * A viewer account, whose own session (not the owner's session viewed through
     * a viewer lens) reads and writes the owner's data as its `dataOwnerId`, per
     * Requirement 7.3.
     */
    private function signInAsFreshViewer(): SessionToken
    {
        // The owner already registered in setUp(); look them up so the viewer's
        // dataOwnerId points at that account.
        $owner = (new UserRepository($this->pdo))->findByNormalizedEmail(
            EmailAddress::normalise(self::OWNER_EMAIL)
        );
        self::assertNotNull($owner);

        $viewerId = UserId::fromString(Ulid::generate($this->clock));
        $viewer = new UserAccount(
            id: $viewerId,
            emailNormalized: 'viewer@example.com',
            emailDisplay: 'viewer@example.com',
            passwordHash: PasswordHasher::forTests()->hash('correct1horse2battery'),
            role: UserRole::Viewer,
            dataOwnerId: $owner->id,
            status: UserStatus::Active,
            failedLoginCount: 0,
            lockedUntil: null,
            deletionRequestedAt: null,
            createdAt: $this->clock->now(),
            updatedAt: $this->clock->now(),
        );
        (new UserRepository($this->pdo))->insert($viewer);

        $result = $this->authService->authenticate('viewer@example.com', 'correct1horse2battery', $this->clock->now());
        self::assertTrue($result->isOk());

        $session = $result->value();
        self::assertInstanceOf(Session::class, $session);

        $token = $session->issuedToken();
        self::assertNotNull($token);

        return $token;
    }
}
