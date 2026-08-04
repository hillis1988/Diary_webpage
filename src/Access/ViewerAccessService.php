<?php

declare(strict_types=1);

namespace Diary\Access;

use Diary\Auth\AuditAction;
use Diary\Auth\AuditContextRole;
use Diary\Auth\AuditLogEntry;
use Diary\Auth\AuditLogRepository;
use Diary\Auth\AuditOutcome;
use Diary\Auth\AuthService;
use Diary\Auth\DuplicateEmailException;
use Diary\Auth\EmailAddress;
use Diary\Auth\InvitationToken;
use Diary\Auth\SessionRepository;
use Diary\Auth\UserAccount;
use Diary\Auth\UserId;
use Diary\Auth\UserRepository;
use Diary\Auth\UserRole;
use Diary\Auth\UserStatus;
use Diary\Auth\ViewerInvitation;
use Diary\Support\Clock;
use Diary\Support\Result;
use Diary\Support\Ulid;

/**
 * Viewer account creation and revocation (Requirements 7.1, 7.4, 7.5).
 *
 * design.md places `createViewer` and `revokeViewer` on Access_Control_Service's
 * interface, but that service takes only a {@see \Diary\Support\Clock}, an
 * {@see AuditLogRepository} and an {@see \Diary\Auth\IpHasher} - it has no
 * dependency on account storage, deliberately, because its own job is a single
 * declarative permission lookup (see its class doc). Viewer lifecycle needs
 * {@see UserRepository} and {@see SessionRepository} instead, so this is a
 * companion service living alongside it in the same namespace: still the
 * Access layer's concern (owner-scoped account management), but not bolted onto
 * the class whose only other job is "allow, redirect, or deny".
 *
 * Like {@see \Diary\Diary\DiaryService} and {@see \Diary\Milestone\MilestoneService},
 * this takes an {@see OwnerId} directly rather than a `SecurityContext`. The
 * viewer management page and controller (task 15.4) own resolving that owner and
 * calling `AccessControlService::authorise()` with `OperationKind::ManageAccess`
 * before reaching either method here (Requirement 7.5) - restricting these
 * operations to an owner context is enforced by that caller, exactly as it is for
 * diary entries and milestones.
 *
 * There is no outbound email in this MVP: {@see createViewer()} hands the raw
 * invitation link's token back in its {@see Result}, for the owner to copy and
 * share themselves. Only the token's hash ever reaches the database.
 */
final class ViewerAccessService
{
    /** How long an unclaimed invitation stays acceptable. */
    public const INVITATION_VALIDITY_DAYS = 7;

    public const VIEWER_NOT_FOUND_ERROR_CODE = 'viewer_not_found';
    public const VIEWER_NOT_FOUND_MESSAGE = 'That viewer account could not be found.';

    public function __construct(
        private readonly UserRepository $users,
        private readonly SessionRepository $sessions,
        private readonly ?AuditLogRepository $auditLog = null,
    ) {
    }

    /**
     * Create a Viewer account linked to this owner's data, `status = 'invited'`,
     * with a single-use invitation token (Requirements 7.1, 7.5).
     *
     * Mirrors {@see AuthService::register()}'s ordering: the address is validated
     * and checked for an existing account before anything is written, so a
     * rejected request leaves no row behind.
     *
     * @return Result<ViewerInvitation>
     */
    public function createViewer(OwnerId $owner, string $email, Clock $clock): Result
    {
        $address = EmailAddress::fromInput($email);

        if (!$address->isValid()) {
            return Result::failure(
                AuthService::EMAIL_INVALID_ERROR_CODE,
                AuthService::EMAIL_INVALID_MESSAGE,
                [AuthService::EMAIL_FIELD => AuthService::EMAIL_INVALID_MESSAGE],
            );
        }

        if ($this->users->existsWithNormalizedEmail($address->normalized())) {
            return self::emailTaken();
        }

        $now = $clock->now();
        $viewerId = UserId::fromString(Ulid::generate($clock));
        $token = InvitationToken::generate();

        $account = UserAccount::newInvitedViewer(
            $viewerId,
            $address,
            $owner->toUserId(),
            $token->hash(),
            $now->modify('+' . self::INVITATION_VALIDITY_DAYS . ' days'),
            $now,
        );

        try {
            $this->users->insert($account);
        } catch (DuplicateEmailException) {
            // Two invitations for one address arrived together; the unique index
            // settled it, and the account that already exists is untouched.
            return self::emailTaken();
        }

        $this->audit(AuditAction::ViewerCreated, $owner->toUserId(), $viewerId, $clock);

        return Result::ok(new ViewerInvitation($viewerId, $token));
    }

    /**
     * Every viewer linked to this owner (Requirement 7.5's viewer management
     * page lists these), ordered by creation. A thin pass-through to
     * {@see UserRepository::findViewersByOwner()}, kept here rather than making
     * the controller depend on `UserRepository` directly - the same reasoning
     * that keeps `createViewer` and `revokeViewer` off that repository's own
     * caller list.
     *
     * @return list<UserAccount>
     */
    public function viewersFor(OwnerId $owner): array
    {
        return $this->users->findViewersByOwner($owner->toUserId());
    }

    /**
     * Revoke a Viewer's access (Requirements 7.4, 7.5): sets `status = 'revoked'`
     * and terminates every one of that Viewer's live sessions at once, so access
     * ends on revocation rather than at their next sign-in.
     *
     * Scoped to a viewer account actually linked to this owner - an id belonging
     * to someone else's viewer, or to an owner account, or to no account at all,
     * is refused as not found rather than acted on.
     *
     * Revoking an already-revoked viewer is a no-op that still succeeds: nothing
     * further is done and no second audit row is written, so retrying a
     * revocation from a stale page is harmless.
     *
     * @return Result<null>
     */
    public function revokeViewer(OwnerId $owner, UserId $viewerId, Clock $clock): Result
    {
        $account = $this->users->findById($viewerId);

        if ($account === null || $account->role !== UserRole::Viewer || !$account->dataOwnerId->equals($owner->toUserId())) {
            return self::notFound();
        }

        if ($account->status === UserStatus::Revoked) {
            return Result::ok(null);
        }

        $now = $clock->now();
        $revoked = $this->users->revokeViewer($viewerId, $owner->toUserId(), $now);

        if (!$revoked) {
            // Lost a race with another revocation of the same account; the end
            // state (revoked, sessions ending) is the same either way.
            return Result::ok(null);
        }

        $this->sessions->terminateAllForUser($viewerId, $now);
        $this->audit(AuditAction::ViewerRevoked, $owner->toUserId(), $viewerId, $clock);

        return Result::ok(null);
    }

    private function audit(AuditAction $action, UserId $actorUserId, UserId $viewerId, Clock $clock): void
    {
        $this->auditLog?->record(new AuditLogEntry(
            id: Ulid::generate($clock),
            actorUserId: $actorUserId,
            contextRole: AuditContextRole::Owner,
            action: $action,
            outcome: AuditOutcome::Success,
            occurredAt: $clock->now(),
            targetType: 'account_access',
            targetId: $viewerId,
        ));
    }

    /**
     * @return Result<null>
     */
    private static function emailTaken(): Result
    {
        return Result::failure(
            AuthService::EMAIL_TAKEN_ERROR_CODE,
            AuthService::EMAIL_TAKEN_MESSAGE,
            [AuthService::EMAIL_FIELD => AuthService::EMAIL_TAKEN_MESSAGE],
        );
    }

    /**
     * @return Result<null>
     */
    private static function notFound(): Result
    {
        return Result::failure(self::VIEWER_NOT_FOUND_ERROR_CODE, self::VIEWER_NOT_FOUND_MESSAGE);
    }
}
