<?php

declare(strict_types=1);

namespace Diary\Auth;

/**
 * The two account roles of the design's `users.role` column: Owner_Role for the
 * Primary_User (Requirement 1.1) and Viewer_Role for a read-only account
 * (Requirement 7.1).
 *
 * Anonymity is a property of a request, not of an account, so it is not a case
 * here: the security context carries that instead.
 */
enum UserRole: string
{
    case Owner = 'owner';
    case Viewer = 'viewer';

    public function isOwner(): bool
    {
        return $this === self::Owner;
    }
}
