<?php

declare(strict_types=1);

namespace Diary\Access;

/**
 * What a caller is told: proceed, go and sign in, or no.
 *
 * The same three shapes as {@see Verdict}, one step closer to the response - the
 * middleware turns `RedirectToLogin` into a 302 with a `Location` and `Deny` into
 * a 403 status page, and only `Allow` reaches a handler.
 */
enum DecisionOutcome: string
{
    case Allow = 'allow';
    case RedirectToLogin = 'redirect_to_login';
    case Deny = 'deny';
}
