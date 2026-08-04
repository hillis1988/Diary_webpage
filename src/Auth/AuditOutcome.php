<?php

declare(strict_types=1);

namespace Diary\Auth;

/**
 * `audit_log.outcome`.
 *
 * `Failure` is "the attempt was wrong" - bad credentials. `Denied` is "the attempt
 * was refused before it was even judged" - an attempt against a locked account,
 * or later an operation a context is not permitted to perform.
 */
enum AuditOutcome: string
{
    case Success = 'success';
    case Failure = 'failure';
    case Denied = 'denied';
}
