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
 * Authentication contributes the first three below and authorisation the fourth;
 * later tasks add viewer grants and revocations, and deletions.
 */
enum AuditAction: string
{
    /** A password was accepted and a session was created (Requirement 2.1). */
    case SignIn = 'sign_in';

    /** Credentials were refused, or an attempt hit a locked account (Requirement 2.2). */
    case SignInFailed = 'sign_in_failed';

    /** The fifth consecutive failure locked the account (Requirement 2.3). */
    case AccountLocked = 'account_locked';

    /**
     * A signed-in context attempted something the permission matrix refuses -
     * in practice, a mutation from a viewer context (Requirements 3.3, 7.3, 7.5,
     * 10.5). The row names the kind of record aimed at in `target_type`, never any
     * of its content.
     */
    case OperationDenied = 'operation_denied';
}
