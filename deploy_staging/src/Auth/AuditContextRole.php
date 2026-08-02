<?php

declare(strict_types=1);

namespace Diary\Auth;

/**
 * `audit_log.context_role`.
 *
 * It is {@see UserRole} plus `anonymous`, because a failed or refused sign-in is
 * recorded before any session exists and therefore has no role yet. The account
 * an attempt was aimed at is still recorded in `actor_user_id`, so a lockout can
 * be traced without pretending the caller was authenticated.
 */
enum AuditContextRole: string
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
}
