<?php

declare(strict_types=1);

namespace Diary\Auth;

/**
 * The lifecycle of an account (`users.status`).
 *
 * An owner registering themselves is `Active` immediately: they choose their own
 * password during registration, so there is nothing left to accept
 * (Requirement 1.1). An invited viewer starts `Invited` with no password hash
 * until they set one, and `Revoked` ends a viewer's access (Requirement 7.4).
 */
enum UserStatus: string
{
    case Invited = 'invited';
    case Active = 'active';
    case Revoked = 'revoked';
}
