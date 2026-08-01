<?php

declare(strict_types=1);

namespace Diary\Access;

use Diary\Auth\AuditAction;
use Diary\Auth\AuditLogEntry;
use Diary\Auth\AuditLogRepository;
use Diary\Auth\AuditOutcome;
use Diary\Auth\IpHasher;
use Diary\Auth\SecurityContext;
use Diary\Support\Clock;
use Diary\Support\Operation;
use Diary\Support\OperationKind;
use Diary\Support\Ulid;
use LogicException;

/**
 * Access_Control_Service: the single enforcement point.
 *
 * Two jobs, and they are deliberately the only two things in the application that
 * may answer their questions:
 *
 *   - {@see authorise()} looks the request up in {@see PermissionMatrix} and turns
 *     the verdict into a {@see Decision}. It contains no rule of its own - the rule
 *     is the table - so Requirements 2.6, 3.3, 5.6, 7.3, 7.5 and 10.5 are enforced
 *     in one lookup rather than scattered across handlers.
 *   - {@see resolveDataOwner()} is the only sanctioned source of owner scoping
 *     (Requirement 4.4). It hands back the *session's* `dataOwnerId`, so a viewer
 *     session reads the owner's data and nothing else, and it throws rather than
 *     returning null for an anonymous context - a null scope would widen a query
 *     to every row in the table, which is the one failure mode worth crashing over.
 *
 * Both key off the {@see SecurityContext}, never off the user row: an Owner_Role
 * user signed in to a viewer account is a viewer here for the whole session
 * (Requirement 7.3).
 *
 * Denials leave a row in `audit_log` - who, in what role, what kind of thing they
 * aimed at, and that it was refused. The trail carries identifiers, a closed action
 * and an outcome only; there is nowhere in {@see AuditLogEntry} to put diary
 * content, and nothing here tries.
 *
 * Anonymous redirects are *not* logged. They are the ordinary state of any request
 * arriving without a cookie - a bookmark, a crawler, a signed-out tab - and writing
 * a row for each would bury the denials that matter under noise, while telling us
 * nothing an access log does not already hold. The authentication attempt that
 * follows is audited by Auth_Service.
 */
final class AccessControlService
{
    /** Requirement 7.3, quoted from the error catalogue. */
    public const READ_ONLY_MESSAGE = Decision::READ_ONLY_MESSAGE;

    public const READ_ONLY_ERROR_CODE = Decision::READ_ONLY_ERROR_CODE;

    /** Requirement 2.6: the two paths an anonymous request may reach. */
    public const LOGIN_PATH = AuthPaths::LOGIN;
    public const REGISTER_PATH = AuthPaths::REGISTER;

    /**
     * The four destinations Requirement 3.2 names. Stated once, here, so the home
     * page and {@see navigationFor()} cannot disagree about where a control points.
     */
    public const DIARY_ENTRY_PATH = '/diary';
    public const CALENDAR_PATH = '/calendar';
    public const SUMMARY_PATH = '/summary';
    public const MILESTONES_PATH = '/milestones';

    /**
     * @param AuditLogRepository|null $auditLog when absent, denials are still
     *                                          refused, just not recorded; the
     *                                          decision never depends on logging
     *                                          succeeding
     * @param IpHasher|null           $ipHasher when absent, `ip_hash` is left null
     *                                          rather than a raw address stored
     */
    public function __construct(
        private readonly Clock $clock,
        private readonly ?AuditLogRepository $auditLog = null,
        private readonly ?IpHasher $ipHasher = null,
    ) {
    }

    /**
     * May this context perform this operation?
     *
     * @param string|null $ipAddress the caller address, recorded on a denial only as
     *                               a keyed hash
     */
    public function authorise(SecurityContext $ctx, Operation $op, ?string $ipAddress = null): Decision
    {
        $verdict = PermissionMatrix::verdictFor($op->kind(), $ctx->contextRole);

        return match ($verdict) {
            Verdict::Allow => Decision::allow(),
            // Requirement 2.6: to the login page, keeping the intended path so the
            // caller lands where they were going once signed in.
            Verdict::RedirectToLogin => Decision::redirectToLogin($op->requestedPath()),
            Verdict::Deny => $this->refuse($ctx, $op, $ipAddress),
        };
    }

    /**
     * Whether the matrix permits the operation outright, without producing a
     * decision. For navigation rendering, which must offer exactly what enforcement
     * would allow (Requirement 3.2).
     */
    public function permits(SecurityContext $ctx, OperationKind $kind): bool
    {
        return PermissionMatrix::allows($kind, $ctx->contextRole);
    }

    /**
     * Exactly the controls this session's context role may use (Requirement 3.2).
     *
     * The calendar and summary links are reads, available to a viewer as well as an
     * owner; the diary entry and milestone links create content, so they are
     * omitted in a viewer context (Requirement 3.3). Each candidate is checked
     * against {@see permits()} rather than hand-matched on role, so a change to the
     * matrix changes the navigation for free instead of needing a second edit here.
     *
     * @return list<NavigationItem>
     */
    public function navigationFor(SecurityContext $ctx): array
    {
        $candidates = [
            [OperationKind::WriteDiaryEntry, new NavigationItem('Diary entry', self::DIARY_ENTRY_PATH)],
            [OperationKind::ReadDiaryData, new NavigationItem('Calendar', self::CALENDAR_PATH)],
            [OperationKind::ReadDiaryData, new NavigationItem('Summary', self::SUMMARY_PATH)],
            [OperationKind::WriteMilestone, new NavigationItem('Milestones', self::MILESTONES_PATH)],
        ];

        $items = [];
        foreach ($candidates as [$kind, $item]) {
            if ($this->permits($ctx, $kind)) {
                $items[] = $item;
            }
        }

        return $items;
    }

    /**
     * The scope every repository call must be given (Requirement 4.4).
     *
     * @throws LogicException for an anonymous context: an unscoped query is a data
     *                        breach, so this fails loudly instead of returning null
     */
    public function resolveDataOwner(SecurityContext $ctx): OwnerId
    {
        if ($ctx->dataOwnerId === null) {
            throw new LogicException(
                'An anonymous request has no data owner; authorise the request before scoping a query.'
            );
        }

        return OwnerId::fromUserId($ctx->dataOwnerId);
    }

    /**
     * Whether an anonymous request may proceed to this path (Requirement 2.6).
     * Everything not on the list is protected.
     */
    public static function isPublicPath(string $path): bool
    {
        return AuthPaths::isPublic($path);
    }

    /**
     * A viewer-context mutation: refused with the read-only message and a 403, and
     * recorded (Requirements 3.3, 7.3).
     */
    private function refuse(SecurityContext $ctx, Operation $op, ?string $ipAddress): Decision
    {
        $this->auditLog?->record(new AuditLogEntry(
            id: Ulid::generate($this->clock),
            actorUserId: $ctx->userId,
            contextRole: $ctx->contextRole->forAudit(),
            action: AuditAction::OperationDenied,
            outcome: AuditOutcome::Denied,
            occurredAt: $this->clock->now(),
            targetType: self::targetTypeFor($op->kind()),
            targetId: $ctx->dataOwnerId,
            ipHash: $this->ipHasher?->hash($ipAddress),
        ));

        return Decision::denyReadOnly();
    }

    /**
     * The kind of record the attempt was aimed at. A record *kind*, never an id of
     * content and never any content: `audit_log` holds no health data.
     */
    private static function targetTypeFor(OperationKind $kind): string
    {
        return match ($kind) {
            OperationKind::ViewAuthPage => 'auth_page',
            OperationKind::ReadDiaryData => 'diary_data',
            OperationKind::WriteDiaryEntry => 'diary_entry',
            OperationKind::WriteMilestone => 'milestone',
            OperationKind::ManageAccess => 'account_access',
        };
    }
}
