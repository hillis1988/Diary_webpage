<?php

declare(strict_types=1);

namespace Diary\Support;

use InvalidArgumentException;
use Stringable;

/**
 * A descriptor of what a request is trying to do.
 *
 * The kind is what the permission matrix decides on. The action is the stable
 * label written to audit_log (identifiers, actions and outcomes only - never
 * content). The requested path lets an anonymous redirect preserve where the
 * user was heading (Requirement 2.6).
 */
final class Operation implements Stringable
{
    private function __construct(
        private readonly OperationKind $kind,
        private readonly string $action,
        private readonly ?string $requestedPath,
    ) {
    }

    public static function of(OperationKind $kind, string $action, ?string $requestedPath = null): self
    {
        if ($action === '') {
            throw new InvalidArgumentException('An operation needs an action label for the audit log.');
        }

        return new self($kind, $action, $requestedPath);
    }

    public static function viewLogin(?string $requestedPath = null): self
    {
        return self::of(OperationKind::ViewAuthPage, 'auth.view_login', $requestedPath);
    }

    public static function viewRegister(?string $requestedPath = null): self
    {
        return self::of(OperationKind::ViewAuthPage, 'auth.view_register', $requestedPath);
    }

    public static function viewAcceptInvitation(?string $requestedPath = null): self
    {
        return self::of(OperationKind::ViewAuthPage, 'auth.view_accept_invitation', $requestedPath);
    }

    public static function readDiaryData(string $action, ?string $requestedPath = null): self
    {
        return self::of(OperationKind::ReadDiaryData, $action, $requestedPath);
    }

    public static function createDiaryEntry(?string $requestedPath = null): self
    {
        return self::of(OperationKind::WriteDiaryEntry, 'diary_entry.create', $requestedPath);
    }

    public static function updateDiaryEntry(?string $requestedPath = null): self
    {
        return self::of(OperationKind::WriteDiaryEntry, 'diary_entry.update', $requestedPath);
    }

    public static function deleteDiaryEntry(?string $requestedPath = null): self
    {
        return self::of(OperationKind::WriteDiaryEntry, 'diary_entry.delete', $requestedPath);
    }

    public static function createMilestone(?string $requestedPath = null): self
    {
        return self::of(OperationKind::WriteMilestone, 'milestone.create', $requestedPath);
    }

    public static function updateMilestone(?string $requestedPath = null): self
    {
        return self::of(OperationKind::WriteMilestone, 'milestone.update', $requestedPath);
    }

    public static function deleteMilestone(?string $requestedPath = null): self
    {
        return self::of(OperationKind::WriteMilestone, 'milestone.delete', $requestedPath);
    }

    public static function createViewer(?string $requestedPath = null): self
    {
        return self::of(OperationKind::ManageAccess, 'viewer.create', $requestedPath);
    }

    public static function revokeViewer(?string $requestedPath = null): self
    {
        return self::of(OperationKind::ManageAccess, 'viewer.revoke', $requestedPath);
    }

    public static function deleteAccount(?string $requestedPath = null): self
    {
        return self::of(OperationKind::ManageAccess, 'account.delete', $requestedPath);
    }

    /**
     * Signing out (Requirement 2.4). Deliberately {@see OperationKind::ReadDiaryData}
     * rather than one of the mutating kinds: the matrix cell that operation needs -
     * redirect an anonymous caller, allow a viewer, allow an owner - is exactly what
     * `ReadDiaryData` already gives every context (see {@see \Diary\Access\PermissionMatrix}),
     * and unlike this, every mutating kind denies a viewer, which would block a
     * Viewer from signing out (Requirement 2.4 draws no such distinction between
     * roles). It also carries no risk of a mislabelled audit row: that cell of the
     * matrix never produces a `Deny`, so {@see \Diary\Access\AccessControlService}'s
     * denial-logging path - the only place an operation kind is turned into an audit
     * `target_type` - is never reached for a sign-out.
     */
    public static function signOut(?string $requestedPath = null): self
    {
        return self::of(OperationKind::ReadDiaryData, 'auth.sign_out', $requestedPath);
    }

    public function kind(): OperationKind
    {
        return $this->kind;
    }

    public function action(): string
    {
        return $this->action;
    }

    public function requestedPath(): ?string
    {
        return $this->requestedPath;
    }

    public function isMutating(): bool
    {
        return $this->kind->isMutating();
    }

    public function withRequestedPath(?string $requestedPath): self
    {
        return new self($this->kind, $this->action, $requestedPath);
    }

    public function __toString(): string
    {
        return $this->action;
    }
}
