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
use Diary\Http\AccountController;
use Diary\Http\CsrfGuard;
use Diary\Http\Request;
use Diary\Http\SessionResolverMiddleware;
use Diary\Storage\PurgeJobRepository;
use Diary\Storage\PurgeService;
use Diary\Support\FixedClock;
use Diary\Support\Ulid;
use Diary\Tests\Unit\Auth\SqliteAuthTables;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * The account deletion page and controller (Requirement 4.5).
 *
 * Owner-only for the whole page, following
 * tests/Unit/Http/ViewerManagementControllerTest.php's shape for a page that is
 * never viewer-readable: both the confirmation GET and the deletion POST are
 * asserted denied in a viewer context, not just the write.
 *
 * Runs {@see PurgeService} for real against an in-memory schema standing in for
 * migrations 001, 002, 007 and 008 - what matters here is that the signed-in
 * owner's row and session are actually gone afterwards, not a mocked purge.
 */
final class AccountControllerTest extends TestCase
{
    private PDO $pdo;
    private FixedClock $clock;
    private AccessControlService $access;
    private UserRepository $users;
    private SessionRepository $sessions;
    private AuthService $authService;
    private PurgeService $purgeService;
    private CsrfGuard $csrf;
    private AccountController $controller;

    protected function setUp(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_sqlite is not loaded.');
        }

        $this->pdo = SqliteAuthTables::connection();
        $this->createPurgeTables();

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

        $this->purgeService = new PurgeService($this->pdo, new PurgeJobRepository($this->pdo), $this->clock);

        $this->access = new AccessControlService($this->clock);
        $this->csrf = CsrfGuard::withMasterKey(str_repeat("\x2b", 32), $this->clock);

        $this->controller = new AccountController(
            $this->access,
            $this->purgeService,
            $this->authService,
            $this->csrf,
            $this->clock,
        );
    }

    private function createPurgeTables(): void
    {
        $this->pdo->exec(
            'CREATE TABLE diary_entries (
                id                 TEXT NOT NULL PRIMARY KEY,
                owner_id           TEXT NOT NULL,
                entry_date         TEXT NOT NULL,
                key_id             TEXT NOT NULL,
                nonce              BLOB NOT NULL,
                payload_ciphertext BLOB NOT NULL,
                created_at         TEXT NOT NULL,
                updated_at         TEXT NOT NULL
            )'
        );

        $this->pdo->exec(
            'CREATE TABLE cbt_recommendations (
                id                 TEXT NOT NULL PRIMARY KEY,
                entry_id           TEXT NOT NULL,
                status             TEXT NOT NULL,
                key_id             TEXT     NULL,
                nonce              BLOB     NULL,
                payload_ciphertext BLOB     NULL,
                provider           TEXT     NULL,
                model              TEXT     NULL,
                attempt_count      INTEGER  NOT NULL DEFAULT 0,
                generated_at       TEXT     NULL
            )'
        );

        $this->pdo->exec(
            'CREATE TABLE milestones (
                id                 TEXT NOT NULL PRIMARY KEY,
                owner_id           TEXT NOT NULL,
                milestone_date     TEXT NOT NULL,
                key_id             TEXT NOT NULL,
                nonce              BLOB NOT NULL,
                payload_ciphertext BLOB NOT NULL,
                created_at         TEXT NOT NULL,
                updated_at         TEXT NOT NULL
            )'
        );

        $this->pdo->exec(
            'CREATE TABLE purge_jobs (
                id            TEXT    NOT NULL PRIMARY KEY,
                user_id       TEXT    NOT NULL,
                requested_at  TEXT    NOT NULL,
                completed_at  TEXT        NULL,
                attempt_count INTEGER NOT NULL DEFAULT 0,
                last_error    TEXT        NULL
            )'
        );
    }

    /**
     * A real owner account, signed in with a real session row - so
     * {@see SessionCookie::readToken()} and {@see AuthService::signOut()} have a
     * genuine token/row pair to work with, not just a SecurityContext built by
     * hand.
     *
     * @return array{context: SecurityContext, token: SessionToken, ownerId: UserId}
     */
    private function signedInOwner(string $email = 'roy@example.com'): array
    {
        $ownerId = UserId::fromString(Ulid::generate($this->clock));
        $this->users->insert(UserAccount::newOwner(
            $ownerId,
            EmailAddress::fromInput($email),
            'hash',
            $this->clock->now(),
        ));

        $token = SessionToken::generate();
        $session = new Session(
            id: $token->id(),
            userId: $ownerId,
            contextRole: UserRole::Owner,
            dataOwnerId: $ownerId,
            createdAt: $this->clock->now(),
            lastActivityAt: $this->clock->now(),
        );
        $this->sessions->insert($session);

        return [
            'context' => SecurityContext::forSession($session),
            'token' => $token,
            'ownerId' => $ownerId,
        ];
    }

    private function viewerContextFor(UserId $ownerId): SecurityContext
    {
        $session = new Session(
            id: SessionId::fromString(str_repeat('c', 64)),
            userId: UserId::fromString(Ulid::generate($this->clock)),
            contextRole: UserRole::Viewer,
            dataOwnerId: $ownerId,
            createdAt: $this->clock->now(),
            lastActivityAt: $this->clock->now(),
        );

        return SecurityContext::forSession($session);
    }

    private function getRequest(SecurityContext $context, ?SessionToken $token = null): Request
    {
        $cookies = $token !== null ? [SessionCookie::NAME => $token->value()] : [];

        return Request::of('GET', AccessControlService::ACCOUNT_DELETE_PATH, cookies: $cookies)
            ->withAttribute(SessionResolverMiddleware::CONTEXT_ATTRIBUTE, $context);
    }

    /**
     * @param array<string, string> $form
     */
    private function postRequest(SecurityContext $context, array $form, ?SessionToken $token = null): Request
    {
        $cookies = $token !== null ? [SessionCookie::NAME => $token->value()] : [];

        return Request::of('POST', AccessControlService::ACCOUNT_DELETE_PATH, form: $form, cookies: $cookies)
            ->withAttribute(SessionResolverMiddleware::CONTEXT_ATTRIBUTE, $context);
    }

    // --- Confirmation page ---

    public function testShowConfirmationInAnOwnerContextRendersTheWarningAndForm(): void
    {
        $owner = $this->signedInOwner();

        $response = $this->controller->showConfirmation($this->getRequest($owner['context']));

        self::assertSame(200, $response->status());
        $html = $response->body();
        self::assertStringContainsString(AccountController::WARNING_MESSAGE, $html);
        self::assertStringContainsString(CsrfGuard::FIELD_NAME, $html);
        self::assertStringContainsString(AccessControlService::ACCOUNT_DELETE_PATH, $html);
    }

    public function testShowConfirmationInAViewerContextIsDenied(): void
    {
        $owner = $this->signedInOwner();
        $viewerContext = $this->viewerContextFor($owner['ownerId']);

        $response = $this->controller->showConfirmation($this->getRequest($viewerContext));

        self::assertSame(403, $response->status());
        self::assertStringContainsString('read-only access', $response->body());
    }

    public function testShowConfirmationAnonymousRedirectsToLogin(): void
    {
        $response = $this->controller->showConfirmation(
            Request::of('GET', AccessControlService::ACCOUNT_DELETE_PATH)
        );

        self::assertSame(302, $response->status());
        self::assertStringContainsString('/login', (string) $response->header('Location'));
    }

    // --- Deletion ---

    public function testDeleteInAnOwnerContextPurgesTheAccountSignsOutAndRedirectsToLogin(): void
    {
        $owner = $this->signedInOwner();
        $token = $this->csrf->issueFor($this->getRequest($owner['context'], $owner['token']));

        $request = $this->postRequest(
            $owner['context'],
            [CsrfGuard::FIELD_NAME => $token],
            $owner['token'],
        );

        $response = $this->controller->delete($request);

        self::assertSame(302, $response->status());
        self::assertStringStartsWith(AccessControlService::LOGIN_PATH, (string) $response->header('Location'));
        self::assertStringContainsString(
            AccountController::DELETED_PARAM . '=1',
            (string) $response->header('Location'),
        );

        $setCookie = $response->header('Set-Cookie');
        self::assertNotNull($setCookie);
        self::assertStringContainsString('Max-Age=0', $setCookie, 'the cookie is cleared, same as sign-out');

        self::assertNull($this->users->findById($owner['ownerId']), 'the account row is gone');
        self::assertNull($this->sessions->findById($owner['token']->id()), 'the session row is gone');
    }

    public function testDeleteInAViewerContextIsDeniedAndLeavesTheAccountUntouched(): void
    {
        $owner = $this->signedInOwner();
        $viewerContext = $this->viewerContextFor($owner['ownerId']);

        $token = $this->csrf->issueFor($this->getRequest($viewerContext));
        $request = $this->postRequest($viewerContext, [CsrfGuard::FIELD_NAME => $token]);

        $response = $this->controller->delete($request);

        self::assertSame(403, $response->status());
        self::assertStringContainsString('read-only access', $response->body());

        self::assertNotNull($this->users->findById($owner['ownerId']), 'a denied deletion writes nothing');
    }

    public function testDeleteAnonymousRedirectsToLoginAndWritesNothing(): void
    {
        $owner = $this->signedInOwner();

        $request = Request::of('POST', AccessControlService::ACCOUNT_DELETE_PATH, form: [
            CsrfGuard::FIELD_NAME => 'irrelevant',
        ]);

        $response = $this->controller->delete($request);

        self::assertSame(302, $response->status());
        self::assertStringContainsString('/login', (string) $response->header('Location'));
        self::assertNotNull($this->users->findById($owner['ownerId']));
    }
}
