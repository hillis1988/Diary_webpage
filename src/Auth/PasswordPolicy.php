<?php

declare(strict_types=1);

namespace Diary\Auth;

use Diary\Support\Result;

/**
 * The password rules registration and viewer activation both have to satisfy.
 *
 * Kept behind an interface because two call sites use it (owner registration and
 * an invited viewer setting their own password, Requirement 7.1) and because the
 * rejection text has to be identical in both places: `describe()` is the single
 * source of that wording (Requirements 1.3, 1.5).
 */
interface PasswordPolicy
{
    /**
     * Machine-readable code carried by every policy rejection.
     */
    public const ERROR_CODE = 'password_policy_not_met';

    /**
     * The form field the rejection message is attached to.
     */
    public const FIELD = 'password';

    /**
     * Accept or reject a candidate password. Runs before any write, so a
     * rejection leaves no row and no hash anywhere (Requirement 1.4).
     *
     * @return Result<null> ok on acceptance; on rejection a failure carrying
     *                      {@see PasswordPolicy::ERROR_CODE} and the text of
     *                      {@see PasswordPolicy::describe()}
     */
    public function validate(string $password): Result;

    /**
     * The policy description shown to the user when a password is rejected.
     */
    public function describe(): string;
}
