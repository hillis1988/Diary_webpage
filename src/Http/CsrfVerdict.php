<?php

declare(strict_types=1);

namespace Diary\Http;

/**
 * Why a CSRF token was accepted or refused.
 *
 * The user sees the same sentence for every refusal - which token went wrong is
 * their problem to solve by reloading the form, and distinguishing "stale" from
 * "forged" out loud only helps an attacker. The distinction is kept here so the
 * server-side log can say which happened, and so tests can assert the specific case.
 */
enum CsrfVerdict: string
{
    case Valid = 'valid';

    /** No token in the body and none in the header. */
    case Missing = 'missing';

    /** Present but not in the expected shape, so it was never one of ours. */
    case Malformed = 'malformed';

    /** Ours, but issued longer ago than the accepted lifetime. */
    case Stale = 'stale';

    /**
     * Well formed but the signature does not match: a forgery, or a token issued
     * for a different session.
     */
    case Mismatched = 'mismatched';

    public function isValid(): bool
    {
        return $this === self::Valid;
    }
}
