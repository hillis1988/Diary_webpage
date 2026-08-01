<?php

declare(strict_types=1);

namespace Diary\Auth;

/**
 * The closed set of things `audit_log.action` may say.
 *
 * An enum rather than free strings so an action name cannot drift between the
 * code that writes a row and the code that reads one, and so no caller can smuggle
 * user-supplied text - let alone diary content - into the action column.
 *
 * Authentication contributes the three below; later tasks add viewer grants and
 * revocations, denied operations and deletions.
 */
enum AuditAction: string
{
    /** A password was accepted and a session was created (Requirement 2.1). */
    case SignIn = 'sign_in';

    /** Credentials were refused, or an attempt hit a locked account (Requirement 2.2). */
    case SignInFailed = 'sign_in_failed';

    /** The fifth consecutive failure locked the account (Requirement 2.3). */
    case AccountLocked = 'account_locked';
}
