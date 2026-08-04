<?php

declare(strict_types=1);

namespace Diary\Tests\Unit\Http;

use Diary\Access\AccessControlService;
use Diary\Access\OwnerId;
use Diary\Access\ViewerAccessService;
use Diary\Auth\AuditLogRepository;
use Diary\Auth\SecurityContext;
use Diary\Auth\Session;
use Diary\Auth\SessionId;
use Diary\Auth\SessionRepository;
use Diary\Auth\UserId;
use Diary\Auth\UserRepository;
use Diary\Auth\UserRole;
use Diary\Http\CsrfGuard;
use Diary\Http\Request;
use Diary\Http\SessionResolverMiddleware;
use Diary\Http\ViewerManagementController;
use Diary\Support\FixedClock;
use Diary\Support\Ulid;
use Diary\Tests\Unit\Auth\SqliteAuthTables;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * The viewer management page and controller (Requirements 7.1, 7.4, 7.5).
 *
 * Owner-only for the whole page, not just its actions: unlike
 * tests/Unit/Http/MilestoneControllerTest.php, the list itself is also denied to
 * a viewer context here, which is what makes this suite assert a 403 on
 * {@see ViewerManagementController::list()} and not just on the invite/revoke
 * handlers.
 *
 * Runs against a real in-memory database, following the same shape as
 * ViewerAccessServiceTest: what these requirements promise is about what the page
 * shows for what ends up in `users`.
 */
final class ViewerManagementControllerTest extends TestCase
{
    private const VIEWER_EMAIL = 'mum@example.com';

    private PDO $pdo;
    private FixedClock $clock;
    private AccessControlService $access;
    private ViewerAccessService $viewerAccess;
    private UserRepository $users;
    private ViewerManagementController $controller;
    private CsrfGuard $csrf;

    protected function setUp(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_sqlite is not loaded.');
        }

        $this->pdo = SqliteAuthTables::connection();
        $this->clock = FixedClock::at('2025-05-01 09:00:00');

        $this->users = new UserRepository($this->pdo);
        $sessions = new SessionRepository($this->pdo);
        $this->viewerAccess = new ViewerAccessService($this->users, $sessions, new AuditLogRepository($this->pdo));

        $this->access = new AccessControlService($this->clock);
        $this->csrf = CsrfGuard::withMasterKey(str_repeat("\x2b", 32), $this->clock);

        $this->controller = new ViewerManagementController(
            $this->access,
            $this->viewerAccess,
            $this->csrf,
            $this->clock,
        );
    }

    private function ownerContext(): SecurityContext
    {
        $userId = UserId::fromString(Ulid::generate($this->clock));
        // Insert the backing row too: revoke/create scope every write to a real
        // owner id, and viewersFor() reads the users table directly.
        $this->users->insert(\Diary\Auth\UserAccount::newOwner(
            $userId,
            \Diary\Auth\EmailAddress::fromInput($userId->toString() . '@example.com'),
            'hash',
            $this->clock->now(),
        ));

        $session = new Session(
            id: SessionId::fromString(str_repeat('a', 64)),
            userId: $userId,
            contextRole: UserRole::Owner,
            dataOwnerId: $userId,
            createdAt: $this->clock->now(),
            lastActivityAt: $this->clock->now(),
        );

        return SecurityContext::forSession($session);
    }

    private function viewerContextFor(SecurityContext $ownerContext): SecurityContext
    {
        $viewerId = UserId::fromString(Ulid::generate($this->clock));
        $session = new Session(
            id: SessionId::fromString(str_repeat('c', 64)),
            userId: $viewerId,
            contextRole: UserRole::Viewer,
            dataOwnerId: $ownerContext->dataOwnerId,
            createdAt: $this->clock->now(),
            lastActivityAt: $this->clock->now(),
        );

        return SecurityContext::forSession($session);
    }

    private function getRequest(SecurityContext $context, string $path = AccessControlService::VIEWERS_PATH): Request
    {
        return Request::of('GET', $path)
            ->withAttribute(SessionResolverMiddleware::CONTEXT_ATTRIBUTE, $context);
    }

    /**
     * @param array<string, string> $form
     */
    private function postRequest(SecurityContext $context, string $path, array $form): Request
    {
        return Request::of('POST', $path, form: $form)
            ->withAttribute(SessionResolverMiddleware::CONTEXT_ATTRIBUTE, $context);
    }

    // --- List view ---

    public function testListInAnOwnerContextShowsEveryViewerWithItsStatus(): void
    {
        $context = $this->ownerContext();
        $owner = $this->access->resolveDataOwner($context);
        $this->viewerAccess->createViewer($owner, self::VIEWER_EMAIL, $this->clock);

        $response = $this->controller->list($this->getRequest($context));

        self::assertSame(200, $response->status());
        $html = $response->body();
        self::assertStringContainsString(self::VIEWER_EMAIL, $html);
        self::assertStringContainsString('Invited', $html);
        self::assertStringContainsString('Revoke', $html);
    }

    public function testListWithNoViewersShowsTheEmptyMessage(): void
    {
        $response = $this->controller->list($this->getRequest($this->ownerContext()));

        self::assertSame(200, $response->status());
        self::assertStringContainsString(ViewerManagementController::NO_VIEWERS_MESSAGE, $response->body());
    }

    public function testListInAViewerContextIsDenied(): void
    {
        $ownerContext = $this->ownerContext();
        $viewerContext = $this->viewerContextFor($ownerContext);

        $response = $this->controller->list($this->getRequest($viewerContext));

        self::assertSame(403, $response->status());
        self::assertStringContainsString('read-only access', $response->body());
    }

    public function testListAnonymousRedirectsToLogin(): void
    {
        $response = $this->controller->list(Request::of('GET', AccessControlService::VIEWERS_PATH));

        self::assertSame(302, $response->status());
        self::assertStringContainsString('/login', (string) $response->header('Location'));
    }

    // --- Invite ---

    public function testInviteWithAValidEmailShowsTheInvitationLink(): void
    {
        $context = $this->ownerContext();
        $token = $this->csrf->issueFor($this->getRequest($context, ViewerManagementController::INVITE_PATH));

        $request = $this->postRequest($context, ViewerManagementController::INVITE_PATH, [
            CsrfGuard::FIELD_NAME => $token,
            ViewerManagementController::EMAIL_FIELD => self::VIEWER_EMAIL,
        ]);

        $response = $this->controller->invite($request);

        self::assertSame(200, $response->status());
        $html = $response->body();
        self::assertStringContainsString(AccessControlService::ACCEPT_INVITATION_PATH, $html);
        self::assertStringContainsString('token=', $html);
        self::assertStringContainsString(self::VIEWER_EMAIL, $html);
    }

    public function testInviteWithADuplicateEmailRedisplaysTheFormWithTheError(): void
    {
        $context = $this->ownerContext();
        $owner = $this->access->resolveDataOwner($context);
        $this->viewerAccess->createViewer($owner, self::VIEWER_EMAIL, $this->clock);

        $token = $this->csrf->issueFor($this->getRequest($context, ViewerManagementController::INVITE_PATH));
        $request = $this->postRequest($context, ViewerManagementController::INVITE_PATH, [
            CsrfGuard::FIELD_NAME => $token,
            ViewerManagementController::EMAIL_FIELD => self::VIEWER_EMAIL,
        ]);

        $response = $this->controller->invite($request);

        self::assertSame(200, $response->status());
        $html = $response->body();
        self::assertStringContainsString(\Diary\Auth\AuthService::EMAIL_TAKEN_MESSAGE, $html);
        self::assertStringNotContainsString(AccessControlService::ACCEPT_INVITATION_PATH, $html);
    }

    public function testInviteInAViewerContextIsDeniedAndWritesNothing(): void
    {
        $ownerContext = $this->ownerContext();
        $viewerContext = $this->viewerContextFor($ownerContext);

        $before = (int) $this->pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();

        $request = $this->postRequest($viewerContext, ViewerManagementController::INVITE_PATH, [
            CsrfGuard::FIELD_NAME => $this->csrf->issueFor($this->getRequest($viewerContext, ViewerManagementController::INVITE_PATH)),
            ViewerManagementController::EMAIL_FIELD => self::VIEWER_EMAIL,
        ]);

        $response = $this->controller->invite($request);

        self::assertSame(403, $response->status());
        self::assertStringContainsString('read-only access', $response->body());
        self::assertSame($before, (int) $this->pdo->query('SELECT COUNT(*) FROM users')->fetchColumn());
    }

    // --- Revoke ---

    public function testRevokeOfAnExistingViewerRedirectsToTheListAndMarksTheViewerRevoked(): void
    {
        $context = $this->ownerContext();
        $owner = $this->access->resolveDataOwner($context);
        $invitation = $this->viewerAccess->createViewer($owner, self::VIEWER_EMAIL, $this->clock)->value();

        $path = ViewerManagementController::revokePath($invitation->viewerId);
        $token = $this->csrf->issueFor($this->getRequest($context, $path));

        $request = $this->postRequest($context, $path, [
            CsrfGuard::FIELD_NAME => $token,
        ]);

        $response = $this->controller->revoke($request, ['id' => $invitation->viewerId->toString()]);

        self::assertSame(302, $response->status());
        self::assertSame(AccessControlService::VIEWERS_PATH, $response->header('Location'));

        $viewer = $this->users->findById($invitation->viewerId);
        self::assertNotNull($viewer);
        self::assertSame(\Diary\Auth\UserStatus::Revoked, $viewer->status);
    }

    public function testRevokeOfAnUnknownIdReturnsNotFound(): void
    {
        $context = $this->ownerContext();
        $unknown = UserId::fromString(Ulid::generate($this->clock));

        $path = ViewerManagementController::revokePath($unknown);
        $request = $this->postRequest($context, $path, [
            CsrfGuard::FIELD_NAME => $this->csrf->issueFor($this->getRequest($context, $path)),
        ]);

        $response = $this->controller->revoke($request, ['id' => $unknown->toString()]);

        self::assertSame(404, $response->status());
    }

    public function testRevokeOfAViewerBelongingToAnotherOwnerReturnsNotFound(): void
    {
        $ownerContext = $this->ownerContext();
        $otherOwnerContext = $this->ownerContext();
        $otherOwner = $this->access->resolveDataOwner($otherOwnerContext);
        $invitation = $this->viewerAccess->createViewer($otherOwner, self::VIEWER_EMAIL, $this->clock)->value();

        $path = ViewerManagementController::revokePath($invitation->viewerId);
        $request = $this->postRequest($ownerContext, $path, [
            CsrfGuard::FIELD_NAME => $this->csrf->issueFor($this->getRequest($ownerContext, $path)),
        ]);

        $response = $this->controller->revoke($request, ['id' => $invitation->viewerId->toString()]);

        self::assertSame(404, $response->status());

        $untouched = $this->users->findById($invitation->viewerId);
        self::assertNotNull($untouched);
        self::assertSame(\Diary\Auth\UserStatus::Invited, $untouched->status);
    }

    public function testRevokeInAViewerContextIsDeniedAndLeavesTheViewerUntouched(): void
    {
        $ownerContext = $this->ownerContext();
        $owner = $this->access->resolveDataOwner($ownerContext);
        $invitation = $this->viewerAccess->createViewer($owner, self::VIEWER_EMAIL, $this->clock)->value();

        $viewerContext = $this->viewerContextFor($ownerContext);
        $path = ViewerManagementController::revokePath($invitation->viewerId);

        $request = $this->postRequest($viewerContext, $path, [
            CsrfGuard::FIELD_NAME => $this->csrf->issueFor($this->getRequest($viewerContext, $path)),
        ]);

        $response = $this->controller->revoke($request, ['id' => $invitation->viewerId->toString()]);

        self::assertSame(403, $response->status());
        self::assertStringContainsString('read-only access', $response->body());

        $untouched = $this->users->findById($invitation->viewerId);
        self::assertNotNull($untouched);
        self::assertSame(\Diary\Auth\UserStatus::Invited, $untouched->status);
    }
}
