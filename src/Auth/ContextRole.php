<?php

declare(strict_types=1);

namespace Diary\Auth;

/**
 * The role a *request* is running under: {@see UserRole} plus `anonymous`.
 *
 * This is the value every authorisation decision keys off, and it is read from the
 * session row rather than from the user row, so an Owner_Role account signed in to
 * a viewer context runs as `viewer` for the whole session (Requirement 7.3).
 *
 * `anonymous` is what a request gets when there is no cookie, no matching session,
 * a signed-out session, or one that has been idle too long - the four ways of
 * failing closed collapse into one case here.
 */
enum ContextRole: string
{
    case Owner = 'owner';
    case Viewer = 'viewer';
    case Anonymous = 'anonymous';

    public static function fromUserRole(UserRole $role): self
    {
        return match ($role) {
            UserRole::Owner => self::Owner,
            UserRole::Viewer => self::Viewer,
        };
    }

    public function isOwner(): bool
    {
        return $this === self::Owner;
    }

    public function isAnonymous(): bool
    {
        return $this === self::Anonymous;
    }

    /**
     * The same role as `audit_log.context_role` spells it.
     */
    public function forAudit(): AuditContextRole
    {
        return match ($this) {
            self::Owner => AuditContextRole::Owner,
            self::Viewer => AuditContextRole::Viewer,
            self::Anonymous => AuditContextRole::Anonymous,
        };
    }
}
