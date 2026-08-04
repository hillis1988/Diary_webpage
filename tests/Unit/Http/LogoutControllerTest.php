<?php

declare(strict_types=1);

namespace Diary\Tests\Unit\Http;

use Diary\Access\AccessControlService;
use Diary\Auth\AuditLogRepository;
use Diary\Auth\AuthService;
use Diary\Auth\DefaultPasswordPolicy;
use Diary\Auth\EmailAddress;
use Diary\Auth\PasswordHasher;
use Diary\Auth\SecurityContext;
use Diary\Auth\Session;
use Diary\Auth\SessionCookie;
use Diary\Auth\SessionId;
use Diary\Auth\SessionRepository;
use Diary\Auth\SessionToken;
use Diary\Auth\UserAccount;
use Diary\Auth\UserId;
use Diary\Auth\UserRepository;
use Diary\Auth\UserRole;
use Diary\Http\LogoutController;
use Diary\Http\Request;
use Diary\Http\SessionResolverMiddleware;
use Diary\Support\FixedClock;
use Diary\Support\Ulid;
use Diary\Tests\Unit\Auth\SqliteAuthTables;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * The sign-out route (Requirement 2.4).
 *
 * Runs {@see AuthService::signOut()} against a real `sessions` row, the way
 * tests/Unit/Http/AccountControllerTest.php runs the account-deletion sign-out
 * step: what matters is that the row is actually terminated afterwards and the
 * cookie the response carries is the clearing one, not a mocked call.
 */
final class LogoutControllerTest extends TestCase
{
    private PDO $pdo;
    private FixedClock $clock;
    private UserRepository $users;
    private SessionRepository $sessions;
    private AuthService $authService;
    private LogoutController $controller;

    protected function setUp(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_sqlite is not loaded.');
        }

        $this->pdo = SqliteAuthTables::connection();
        $this->clock = FixedClock::at('2025-06-01 09:00:00');

        $this->users = new UserRepository($this->pdo);
        $this->sessions = new SessionRepository($this->pdo);

        $this->authService = new AuthService(
            $this->users,
            new DefaultPasswordPolicy(),
            $this->clock,
            PasswordHasher::forTests(),
            $this->sessions,
            new AuditLogRepository($this->pdo),
        );

        $this->controller = new LogoutController(
            new AccessControlService($this->clock),
            $this->authService,
            $this->clock,
        );
    }

    /**
     * @return array{context: SecurityContext, token: SessionToken}
     */
    private function signedInUser(UserRole $role, ?UserId $dataOwnerId = null): array
    {
        $userId = UserId::fromString(Ulid::generate($this->clock));
        $this->users->insert(UserAccount::newOwner(
            $userId,
            EmailAddress::fromInput('user' . $userId->toString() . '@example.com'),
            'hash',
            $this->clock->now(),
        ));

        $token = SessionToken::generate();
        $session = new Session(
            id: $token->id(),
            userId: $userId,
            contextRole: $role,
            dataOwnerId: $dataOwnerId ?? $userId,
            createdAt: $this->clock->now(),
            lastActivityAt: $this->clock->now(),
        );
        $this->sessions->insert($session);

        return ['context' => SecurityContext::forSession($session), 'token' => $token];
    }

    private function postRequest(SecurityContext $context, ?SessionToken $token): Request
    {
        $cookies = $token !== null ? [SessionCookie::NAME => $token->value()] : [];

        return Request::of('POST', AccessControlService::LOGOUT_PATH, cookies: $cookies)
            ->withAttribute(SessionResolverMiddleware::CONTEXT_ATTRIBUTE, $context);
    }

    public function testSignOutForAnOwnerTerminatesTheSessionClearsTheCookieAndRedirectsToLogin(): void
    {
        $owner = $this->signedInUser(UserRole::Owner);

        $response = $this->controller->signOut($this->postRequest($owner['context'], $owner['token']));

        self::assertSame(302, $response->status());
        self::assertSame(AccessControlService::LOGIN_PATH, $response->header('Location'));

        $setCookie = $response->header('Set-Cookie');
        self::assertNotNull($setCookie);
        self::assertStringContainsString('Max-Age=0', $setCookie);

        self::assertNull($this->authService->resolveSession($owner['token']->value(), $this->clock->now()));
    }

    public function testSignOutForAViewerIsAllowedTooAndEndsTheSession(): void
    {
        $ownerId = UserId::fromString(Ulid::generate($this->clock));
        $viewer = $this->signedInUser(UserRole::Viewer, $ownerId);

        $response = $this->controller->signOut($this->postRequest($viewer['context'], $viewer['token']));

        self::assertSame(302, $response->status());
        self::assertSame(AccessControlService::LOGIN_PATH, $response->header('Location'));
        self::assertNull($this->authService->resolveSession($viewer['token']->value(), $this->clock->now()));
    }

    public function testSignOutIsIdempotent(): void
    {
        $owner = $this->signedInUser(UserRole::Owner);

        $this->controller->signOut($this->postRequest($owner['context'], $owner['token']));
        $response = $this->controller->signOut($this->postRequest($owner['context'], $owner['token']));

        self::assertSame(302, $response->status());
        self::assertSame(AccessControlService::LOGIN_PATH, $response->header('Location'));
    }

    public function testSignOutWithNoCookiePresentStillRedirectsToLogin(): void
    {
        $owner = $this->signedInUser(UserRole::Owner);

        // The resolved context is authenticated (as it would be if session
        // resolution had somehow succeeded without a cookie attribute reaching
        // this controller), but there is no cookie for the handler to read a
        // token from - nothing to terminate, and no error either.
        $response = $this->controller->signOut($this->postRequest($owner['context'], null));

        self::assertSame(302, $response->status());
        self::assertSame(AccessControlService::LOGIN_PATH, $response->header('Location'));
    }

    public function testSignOutAnonymousRedirectsToLogin(): void
    {
        $response = $this->controller->signOut(Request::of('POST', AccessControlService::LOGOUT_PATH));

        self::assertSame(302, $response->status());
        self::assertStringContainsString(AccessControlService::LOGIN_PATH, (string) $response->header('Location'));
    }
}
