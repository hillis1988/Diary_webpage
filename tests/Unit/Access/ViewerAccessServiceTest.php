<?php

declare(strict_types=1);

namespace Diary\Tests\Unit\Access;

use Diary\Access\OwnerId;
use Diary\Access\ViewerAccessService;
use Diary\Auth\AuditAction;
use Diary\Auth\AuditLogRepository;
use Diary\Auth\AuthService;
use Diary\Auth\DefaultPasswordPolicy;
use Diary\Auth\PasswordHasher;
use Diary\Auth\Session;
use Diary\Auth\SessionRepository;
use Diary\Auth\SessionToken;
use Diary\Auth\UserId;
use Diary\Auth\UserRepository;
use Diary\Auth\UserRole;
use Diary\Auth\UserStatus;
use Diary\Support\FixedClock;
use Diary\Support\Ulid;
use Diary\Tests\Unit\Auth\SqliteAuthTables;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Viewer creation and revocation (Requirements 7.1, 7.4, 7.5).
 *
 * Runs against a real in-memory database, following the same shape as
 * AuthServiceRegistrationTest and AuthServiceAuthenticateTest: what these
 * requirements promise is about what ends up in `users`, `sessions` and
 * `audit_log`.
 */
final class ViewerAccessServiceTest extends TestCase
{
    private const OWNER_EMAIL = 'roy@example.com';
    private const OWNER_PASSWORD = 'correct1horse2battery';
    private const VIEWER_EMAIL = 'mum@example.com';

    private PDO $pdo;
    private UserRepository $users;
    private SessionRepository $sessions;
    private AuditLogRepository $auditLog;
    private ViewerAccessService $viewerAccess;
    private AuthService $auth;
    private FixedClock $clock;
    private UserId $ownerId;

    protected function setUp(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_sqlite is not loaded.');
        }

        $this->pdo = SqliteAuthTables::connection();
        $this->clock = FixedClock::at('2025-05-01 09:00:00');
        $this->users = new UserRepository($this->pdo);
        $this->sessions = new SessionRepository($this->pdo);
        $this->auditLog = new AuditLogRepository($this->pdo);
        $this->viewerAccess = new ViewerAccessService($this->users, $this->sessions, $this->auditLog);

        $this->auth = new AuthService(
            $this->users,
            new DefaultPasswordPolicy(),
            $this->clock,
            PasswordHasher::forTests(),
            $this->sessions,
            $this->auditLog,
        );

        $registration = $this->auth->register(self::OWNER_EMAIL, self::OWNER_PASSWORD);
        self::assertTrue($registration->isOk());
        $this->ownerId = $registration->value();
    }

    private function ownerScope(): OwnerId
    {
        return OwnerId::fromUserId($this->ownerId);
    }

    public function testCreateViewerCreatesAnInvitedAccountLinkedToTheOwnerWithNoPassword(): void
    {
        $result = $this->viewerAccess->createViewer($this->ownerScope(), self::VIEWER_EMAIL, $this->clock);

        self::assertTrue($result->isOk(), (string) $result->message());
        $invitation = $result->value();

        $viewer = $this->users->findById($invitation->viewerId);
        self::assertNotNull($viewer);
        self::assertSame(UserRole::Viewer, $viewer->role);
        self::assertSame(UserStatus::Invited, $viewer->status);
        self::assertTrue($viewer->dataOwnerId->equals($this->ownerId));
        self::assertNull($viewer->passwordHash, 'no password until the invitation is accepted');
        self::assertNotNull($viewer->invitationTokenHash);
        self::assertSame($invitation->token->hash(), $viewer->invitationTokenHash);
        self::assertNotNull($viewer->invitationExpiresAt);
        self::assertSame('2025-05-08 09:00:00', $viewer->invitationExpiresAt->format('Y-m-d H:i:s'));

        // The raw token is only ever recoverable from the Result, never stored.
        $row = $this->pdo->query('SELECT invitation_token_hash FROM users WHERE id = '
            . $this->pdo->quote($invitation->viewerId->toString()))->fetch(PDO::FETCH_ASSOC);
        self::assertNotFalse($row);
        self::assertStringNotContainsString($invitation->token->value(), (string) $row['invitation_token_hash']);
    }

    public function testCreateViewerRejectsAnAlreadyRegisteredEmailAndWritesNothing(): void
    {
        $before = (int) $this->pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();

        $result = $this->viewerAccess->createViewer($this->ownerScope(), self::OWNER_EMAIL, $this->clock);

        self::assertTrue($result->isFailure());
        self::assertTrue($result->hasErrorCode(AuthService::EMAIL_TAKEN_ERROR_CODE));
        self::assertSame($before, (int) $this->pdo->query('SELECT COUNT(*) FROM users')->fetchColumn());
    }

    public function testCreateViewerIsRecordedInTheAuditLog(): void
    {
        $result = $this->viewerAccess->createViewer($this->ownerScope(), self::VIEWER_EMAIL, $this->clock);
        self::assertTrue($result->isOk());

        $audit = SqliteAuthTables::auditLog($this->pdo);
        self::assertCount(1, $audit);
        self::assertSame(AuditAction::ViewerCreated->value, $audit[0]['action']);
        self::assertSame('success', $audit[0]['outcome']);
        self::assertSame($this->ownerId->toString(), $audit[0]['actor_user_id']);
        self::assertSame($result->value()->viewerId->toString(), $audit[0]['target_id']);
        self::assertSame('owner', $audit[0]['context_role']);
    }

    public function testAcceptingAValidInvitationMovesTheViewerToActiveWithAWorkingPassword(): void
    {
        $created = $this->viewerAccess->createViewer($this->ownerScope(), self::VIEWER_EMAIL, $this->clock);
        self::assertTrue($created->isOk());
        $invitation = $created->value();

        $this->clock->advanceMinutes(30);
        $result = $this->auth->acceptViewerInvitation(
            $invitation->token->value(),
            'brandnew12password',
            $this->clock->now()
        );

        self::assertTrue($result->isOk(), (string) $result->message());

        $viewer = $this->users->findById($invitation->viewerId);
        self::assertNotNull($viewer);
        self::assertSame(UserStatus::Active, $viewer->status);
        self::assertNotNull($viewer->passwordHash);
        self::assertTrue(password_verify('brandnew12password', $viewer->passwordHash));
        self::assertNull($viewer->invitationTokenHash, 'the token is cleared, making it single-use');
        self::assertNull($viewer->invitationExpiresAt);

        // The viewer can now sign in.
        $signIn = $this->auth->authenticate(self::VIEWER_EMAIL, 'brandnew12password', $this->clock->now());
        self::assertTrue($signIn->isOk());
    }

    public function testAcceptingATokenThatDoesNotMatchAnyInvitationIsRejected(): void
    {
        $result = $this->auth->acceptViewerInvitation(str_repeat('a', 64), 'brandnew12password', $this->clock->now());

        self::assertTrue($result->isFailure());
        self::assertSame(AuthService::INVITATION_INVALID_ERROR_CODE, $result->errorCode());
    }

    public function testAcceptingAMalformedTokenIsRejected(): void
    {
        $result = $this->auth->acceptViewerInvitation('not-a-token', 'brandnew12password', $this->clock->now());

        self::assertTrue($result->isFailure());
        self::assertSame(AuthService::INVITATION_INVALID_ERROR_CODE, $result->errorCode());
    }

    public function testAcceptingAnExpiredInvitationIsRejected(): void
    {
        $created = $this->viewerAccess->createViewer($this->ownerScope(), self::VIEWER_EMAIL, $this->clock);
        $invitation = $created->value();

        $this->clock->advanceDays(ViewerAccessService::INVITATION_VALIDITY_DAYS + 1);
        $result = $this->auth->acceptViewerInvitation(
            $invitation->token->value(),
            'brandnew12password',
            $this->clock->now()
        );

        self::assertTrue($result->isFailure());
        self::assertSame(AuthService::INVITATION_INVALID_ERROR_CODE, $result->errorCode());

        $viewer = $this->users->findById($invitation->viewerId);
        self::assertNotNull($viewer);
        self::assertSame(UserStatus::Invited, $viewer->status, 'an expired invitation stays invited');
    }

    public function testAcceptingAnAlreadyUsedTokenASecondTimeIsRejected(): void
    {
        $created = $this->viewerAccess->createViewer($this->ownerScope(), self::VIEWER_EMAIL, $this->clock);
        $invitation = $created->value();

        $first = $this->auth->acceptViewerInvitation(
            $invitation->token->value(),
            'brandnew12password',
            $this->clock->now()
        );
        self::assertTrue($first->isOk());

        $second = $this->auth->acceptViewerInvitation(
            $invitation->token->value(),
            'anothervalid12pass',
            $this->clock->now()
        );

        self::assertTrue($second->isFailure());
        self::assertSame(AuthService::INVITATION_INVALID_ERROR_CODE, $second->errorCode());
    }

    public function testAcceptingAnInvitationWithAPasswordFailingThePolicyIsRejected(): void
    {
        $created = $this->viewerAccess->createViewer($this->ownerScope(), self::VIEWER_EMAIL, $this->clock);
        $invitation = $created->value();

        $result = $this->auth->acceptViewerInvitation($invitation->token->value(), 'short', $this->clock->now());

        self::assertTrue($result->isFailure());
        self::assertNotSame(AuthService::INVITATION_INVALID_ERROR_CODE, $result->errorCode());

        $viewer = $this->users->findById($invitation->viewerId);
        self::assertNotNull($viewer);
        self::assertSame(UserStatus::Invited, $viewer->status, 'a rejected password leaves the invitation usable');
        self::assertNotNull($viewer->invitationTokenHash);
    }

    public function testRevokeViewerSetsStatusToRevokedAndTerminatesTheirSessions(): void
    {
        $viewerId = $this->activeViewer();
        $token = $this->signInAsViewer($viewerId);

        $ctx = $this->auth->resolveSession($token->value(), $this->clock->now());
        self::assertNotNull($ctx, 'the viewer session should resolve before revocation');

        $result = $this->viewerAccess->revokeViewer($this->ownerScope(), $viewerId, $this->clock);

        self::assertTrue($result->isOk());

        $viewer = $this->users->findById($viewerId);
        self::assertNotNull($viewer);
        self::assertSame(UserStatus::Revoked, $viewer->status);

        $sessions = SqliteAuthTables::sessions($this->pdo);
        self::assertCount(1, $sessions);
        self::assertNotNull($sessions[0]['terminated_at'], 'the live session must be terminated immediately');
    }

    public function testARevokedViewerCannotReAuthenticate(): void
    {
        $viewerId = $this->activeViewer();
        $this->viewerAccess->revokeViewer($this->ownerScope(), $viewerId, $this->clock);

        $result = $this->auth->authenticate(self::VIEWER_EMAIL, 'brandnew12password', $this->clock->now());

        self::assertTrue($result->isFailure());
        self::assertSame(AuthService::INCORRECT_CREDENTIALS_ERROR_CODE, $result->errorCode());
        self::assertSame([], SqliteAuthTables::sessions($this->pdo), 'no new session for a revoked account');
    }

    public function testARevokedViewerSessionNoLongerResolves(): void
    {
        $viewerId = $this->activeViewer();
        $token = $this->signInAsViewer($viewerId);

        $this->viewerAccess->revokeViewer($this->ownerScope(), $viewerId, $this->clock);

        $ctx = $this->auth->resolveSession($token->value(), $this->clock->now());

        self::assertNull($ctx, 'a terminated session must resolve to anonymous');
    }

    public function testRevokeViewerIsRecordedInTheAuditLog(): void
    {
        $viewerId = $this->activeViewer();

        $result = $this->viewerAccess->revokeViewer($this->ownerScope(), $viewerId, $this->clock);
        self::assertTrue($result->isOk());

        $audit = SqliteAuthTables::auditLog($this->pdo);
        $revocationRows = array_values(array_filter(
            $audit,
            static fn (array $row): bool => $row['action'] === AuditAction::ViewerRevoked->value
        ));

        self::assertCount(1, $revocationRows);
        self::assertSame($this->ownerId->toString(), $revocationRows[0]['actor_user_id']);
        self::assertSame($viewerId->toString(), $revocationRows[0]['target_id']);
        self::assertSame('success', $revocationRows[0]['outcome']);
    }

    public function testRevokeViewerRefusesAnAccountNotLinkedToThisOwner(): void
    {
        // A second owner and a viewer linked to *them*, not to $this->ownerId.
        $otherOwner = $this->auth->register('other@example.com', self::OWNER_PASSWORD);
        self::assertTrue($otherOwner->isOk());

        $created = $this->viewerAccess->createViewer(
            OwnerId::fromUserId($otherOwner->value()),
            'their-viewer@example.com',
            $this->clock
        );
        self::assertTrue($created->isOk());
        $otherViewerId = $created->value()->viewerId;

        $result = $this->viewerAccess->revokeViewer($this->ownerScope(), $otherViewerId, $this->clock);

        self::assertTrue($result->isFailure());
        self::assertSame(ViewerAccessService::VIEWER_NOT_FOUND_ERROR_CODE, $result->errorCode());

        $untouched = $this->users->findById($otherViewerId);
        self::assertNotNull($untouched);
        self::assertSame(UserStatus::Invited, $untouched->status, 'the other owner\'s viewer must be untouched');
    }

    public function testRevokeViewerRefusesAnUnknownId(): void
    {
        $unknown = UserId::fromString(Ulid::generate($this->clock));

        $result = $this->viewerAccess->revokeViewer($this->ownerScope(), $unknown, $this->clock);

        self::assertTrue($result->isFailure());
        self::assertSame(ViewerAccessService::VIEWER_NOT_FOUND_ERROR_CODE, $result->errorCode());
    }

    public function testRevokingAnAlreadyRevokedViewerSucceedsWithNoSecondAuditRow(): void
    {
        $viewerId = $this->activeViewer();

        $first = $this->viewerAccess->revokeViewer($this->ownerScope(), $viewerId, $this->clock);
        self::assertTrue($first->isOk());

        $second = $this->viewerAccess->revokeViewer($this->ownerScope(), $viewerId, $this->clock);
        self::assertTrue($second->isOk());

        $audit = SqliteAuthTables::auditLog($this->pdo);
        $revocationRows = array_filter(
            $audit,
            static fn (array $row): bool => $row['action'] === AuditAction::ViewerRevoked->value
        );

        self::assertCount(1, $revocationRows, 'only the first revocation writes a row');
    }

    /**
     * Create a viewer and accept the invitation, so the resulting account is
     * active with a known password.
     */
    private function activeViewer(): UserId
    {
        $created = $this->viewerAccess->createViewer($this->ownerScope(), self::VIEWER_EMAIL, $this->clock);
        self::assertTrue($created->isOk());
        $invitation = $created->value();

        $accepted = $this->auth->acceptViewerInvitation(
            $invitation->token->value(),
            'brandnew12password',
            $this->clock->now()
        );
        self::assertTrue($accepted->isOk());

        return $invitation->viewerId;
    }

    private function signInAsViewer(UserId $viewerId): SessionToken
    {
        $result = $this->auth->authenticate(self::VIEWER_EMAIL, 'brandnew12password', $this->clock->now());
        self::assertTrue($result->isOk());

        $session = $result->value();
        self::assertInstanceOf(Session::class, $session);

        $token = $session->issuedToken();
        self::assertNotNull($token);

        return $token;
    }
}
