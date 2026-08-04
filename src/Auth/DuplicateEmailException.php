<?php

declare(strict_types=1);

namespace Diary\Auth;

use RuntimeException;

/**
 * Raised when an insert loses the race against the unique index on
 * `users.email_normalized`.
 *
 * Auth_Service checks for an existing account before inserting, so this is the
 * narrow window where two registrations for the same address arrive at once. The
 * database, not the check, is what makes Requirement 1.2 true; this exception is
 * how the insert reports that the index rejected the row so the caller can return
 * the ordinary already-registered message.
 */
final class DuplicateEmailException extends RuntimeException
{
}
