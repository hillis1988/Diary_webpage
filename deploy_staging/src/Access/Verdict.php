<?php

declare(strict_types=1);

namespace Diary\Access;

/**
 * The three answers the permission matrix can give.
 *
 * A verdict is what the *table* says; a {@see Decision} is what the request is
 * told, with a status, a message and a redirect location attached. Keeping them
 * apart is what lets the matrix stay a plain data table with no HTTP in it.
 */
enum Verdict: string
{
    /** The context may perform the operation. */
    case Allow = 'allow';

    /** Nobody is signed in and the route is protected (Requirement 2.6). */
    case RedirectToLogin = 'redirect_to_login';

    /**
     * Signed in, but in a context that may not do this: every mutating kind in a
     * viewer context (Requirements 3.3, 5.6, 7.3, 7.5, 10.5).
     */
    case Deny = 'deny';
}
