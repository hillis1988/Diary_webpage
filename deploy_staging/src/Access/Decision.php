<?php

declare(strict_types=1);

namespace Diary\Access;

use Diary\Support\Result;
use LogicException;

/**
 * The answer {@see AccessControlService::authorise()} gives, and the only thing a
 * caller is allowed to act on.
 *
 * Three shapes, made by three named constructors and nothing else:
 *
 *   - {@see allow()} - proceed to the handler;
 *   - {@see redirectToLogin()} - 302 to the login page, carrying where the caller
 *     was heading (Requirement 2.6);
 *   - {@see denyReadOnly()} - 403 and the read-only message, with no write
 *     attempted (Requirements 3.3, 5.6, 7.3, 7.5, 10.5).
 *
 * A decision is immutable and there is no way to build a fourth kind, so a
 * middleware handling all three has handled every case. {@see isAllowed()} is the
 * single question a handler asks; everything else on here is for rendering the
 * refusal.
 */
final class Decision
{
    public const REDIRECT_STATUS = 302;
    public const DENIED_STATUS = 403;
    private const OK_STATUS = 200;

    public const READ_ONLY_ERROR_CODE = 'read_only_context';

    /**
     * The error catalogue wording for Requirement 7.3. It names the *account's
     * context*, not the user, because an Owner_Role user signed in to a viewer
     * account sees this too - and it says nothing about what the data contains.
     */
    public const READ_ONLY_MESSAGE = 'This account has read-only access';

    private function __construct(
        private readonly DecisionOutcome $outcome,
        private readonly int $status,
        private readonly ?string $message = null,
        private readonly ?string $errorCode = null,
        private readonly ?string $location = null,
        private readonly ?string $intendedPath = null,
    ) {
    }

    public static function allow(): self
    {
        return new self(DecisionOutcome::Allow, self::OK_STATUS);
    }

    /**
     * @param string|null $intendedPath where the caller was heading; kept only when
     *                                  it is a safe local path
     *                                  ({@see AuthPaths::sanitiseReturnPath()})
     */
    public static function redirectToLogin(?string $intendedPath = null): self
    {
        $returnTo = AuthPaths::sanitiseReturnPath($intendedPath);

        return new self(
            outcome: DecisionOutcome::RedirectToLogin,
            status: self::REDIRECT_STATUS,
            location: AuthPaths::loginLocationFor($returnTo),
            intendedPath: $returnTo,
        );
    }

    public static function denyReadOnly(): self
    {
        return new self(
            outcome: DecisionOutcome::Deny,
            status: self::DENIED_STATUS,
            message: self::READ_ONLY_MESSAGE,
            errorCode: self::READ_ONLY_ERROR_CODE,
        );
    }

    public function outcome(): DecisionOutcome
    {
        return $this->outcome;
    }

    public function isAllowed(): bool
    {
        return $this->outcome === DecisionOutcome::Allow;
    }

    public function isRedirectToLogin(): bool
    {
        return $this->outcome === DecisionOutcome::RedirectToLogin;
    }

    public function isDenied(): bool
    {
        return $this->outcome === DecisionOutcome::Deny;
    }

    /**
     * 200 for an allowed operation, 302 for a redirect, 403 for a denial.
     */
    public function status(): int
    {
        return $this->status;
    }

    /**
     * The user-facing message on a denial, and null otherwise.
     */
    public function message(): ?string
    {
        return $this->message;
    }

    public function errorCode(): ?string
    {
        return $this->errorCode;
    }

    /**
     * The `Location` value for a redirect, and null otherwise.
     */
    public function location(): ?string
    {
        if ($this->outcome !== DecisionOutcome::RedirectToLogin) {
            return null;
        }

        return $this->location;
    }

    /**
     * The path the redirect preserved, or null when there was none to keep.
     */
    public function intendedPath(): ?string
    {
        return $this->intendedPath;
    }

    /**
     * The refusal as a {@see Result}, for handlers that report failures that way
     * rather than by rendering a status page.
     *
     * @return Result<null>
     */
    public function toResult(): Result
    {
        if ($this->outcome !== DecisionOutcome::Deny) {
            throw new LogicException('Only a denial has a failure result; check isDenied() first.');
        }

        return Result::failure((string) $this->errorCode, (string) $this->message);
    }
}
