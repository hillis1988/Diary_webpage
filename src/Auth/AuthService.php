<?php

declare(strict_types=1);

namespace Diary\Auth;

use DateInterval;
use DateTimeImmutable;
use Diary\Support\Clock;
use Diary\Support\Result;
use Diary\Support\Ulid;
use LogicException;

/**
 * Auth_Service: registration, authentication, session resolution and sign-out.
 *
 * Registration order matters, and it is fixed here (Requirements 1.1 to 1.4):
 *
 *   1. the email is normalised and checked for shape;
 *   2. the password is checked against the policy;
 *   3. the normalised address is checked for an existing account;
 *   4. only then is a hash produced and a single row inserted.
 *
 * Nothing before step 4 writes anything, so a rejected registration leaves no
 * row and no hash anywhere - and because a duplicate is answered with a refusal
 * rather than an update, the account that already exists keeps its hash and its
 * timestamps untouched.
 *
 * Authentication (Requirements 2.1 to 2.3) is ordered just as deliberately:
 *
 *   1. the account is looked up by normalised email;
 *   2. a live lock is refused before the password is looked at;
 *   3. only then is the password verified;
 *   4. the counter, the lock and the session row are written, and every outcome
 *      leaves a row in `audit_log`.
 *
 * Two things are true of every refusal: the message is the same whether the email
 * is unknown or the password is wrong, and the work done is the same - an absent
 * account and a locked account both run a throwaway verify so a caller cannot
 * time the difference.
 *
 * Sessions (Requirements 2.4, 2.5) are decided server-side, from the row rather
 * than from the cookie. A cookie proves only which row to look at:
 * {@see resolveSession()} answers with a {@see SecurityContext} solely when that
 * row is neither terminated nor idle past {@see IDLE_TIMEOUT_MINUTES}, and every
 * other case - no cookie, a malformed one, an unknown id, a signed-out session,
 * an idle one - answers null, which the caller turns into
 * {@see SecurityContext::anonymous()}. Failing closed is the default, not a branch.
 */
final class AuthService
{
    public const EMAIL_TAKEN_ERROR_CODE = 'email_already_registered';

    /** Error catalogue wording for Requirement 1.2. */
    public const EMAIL_TAKEN_MESSAGE = 'That email address is already registered';

    public const EMAIL_INVALID_ERROR_CODE = 'email_invalid';

    /** Requirement 1.1 admits only a valid address; this is what the user is told otherwise. */
    public const EMAIL_INVALID_MESSAGE = 'Please enter a valid email address';

    /** The form field both email messages are attached to. */
    public const EMAIL_FIELD = 'email';

    public const INCORRECT_CREDENTIALS_ERROR_CODE = 'credentials_incorrect';

    /**
     * Requirement 2.2. One message for a wrong password and for an email that has
     * no account: the wording deliberately names neither, so a caller cannot use
     * sign-in to find out which addresses are registered.
     */
    public const INCORRECT_CREDENTIALS_MESSAGE = 'Your email address or password is incorrect';

    public const ACCOUNT_LOCKED_ERROR_CODE = 'account_locked';

    /** Requirement 2.3, told to whoever meets a lock that is still running. */
    public const ACCOUNT_LOCKED_MESSAGE = 'Too many failed sign-in attempts. Please try again in 15 minutes';

    /** Requirement 2.3: the fifth consecutive failure locks the account. */
    public const MAX_FAILED_ATTEMPTS = 5;

    /** Requirement 2.3: and it stays locked for a quarter of an hour. */
    public const LOCKOUT_MINUTES = 15;

    /** Requirement 2.5: half an hour without a request ends the session. */
    public const IDLE_TIMEOUT_MINUTES = 30;

    public const SESSION_ENDED_ERROR_CODE = 'session_ended';

    /**
     * Requirements 2.4 and 2.5 share one message: a session that was signed out and
     * one that timed out are told apart nowhere the user can see, and either way the
     * only way on is to sign in again.
     */
    public const SESSION_ENDED_MESSAGE = 'Your session ended. Please sign in again';

    /**
     * A hash of nothing anybody knows, verified against when there is no real hash
     * to check, so an absent or locked account costs the same as a wrong password.
     * Built once per service instance and never stored.
     */
    private ?string $timingHash = null;

    /**
     * @param SessionRepository|null   $sessions  required by {@see authenticate()};
     *                                            registration alone does not need it
     * @param AuditLogRepository|null  $auditLog  likewise
     * @param IpHasher|null            $ipHasher  when absent, `ip_hash` is left null
     *                                            rather than a raw address being stored
     */
    public function __construct(
        private readonly UserRepository $users,
        private readonly PasswordPolicy $passwordPolicy,
        private readonly Clock $clock,
        private readonly PasswordHasher $passwordHasher = new PasswordHasher(),
        private readonly ?SessionRepository $sessions = null,
        private readonly ?AuditLogRepository $auditLog = null,
        private readonly ?IpHasher $ipHasher = null,
    ) {
    }

    /**
     * Create the Primary_User's account.
     *
     * @param string $email    as typed; stored as typed in `email_display` and
     *                         trimmed plus lowercased in `email_normalized`
     * @param string $password never stored, logged or returned - only hashed
     *
     * @return Result<UserId> the new account's id, or a failure carrying the
     *                        policy description (Requirement 1.3), the
     *                        already-registered message (Requirement 1.2), or the
     *                        invalid-address message (Requirement 1.1)
     */
    public function register(string $email, string $password): Result
    {
        $address = EmailAddress::fromInput($email);

        if (!$address->isValid()) {
            return Result::failure(
                self::EMAIL_INVALID_ERROR_CODE,
                self::EMAIL_INVALID_MESSAGE,
                [self::EMAIL_FIELD => self::EMAIL_INVALID_MESSAGE],
            );
        }

        // Before the uniqueness lookup and before any hashing, so a password the
        // policy rejects can never produce a stored hash (Requirements 1.3, 1.4).
        $policy = $this->passwordPolicy->validate($password);

        if ($policy->isFailure()) {
            return $policy;
        }

        if ($this->users->existsWithNormalizedEmail($address->normalized())) {
            return self::emailTaken();
        }

        $id = UserId::fromString(Ulid::generate($this->clock));

        $account = UserAccount::newOwner(
            $id,
            $address,
            $this->passwordHasher->hash($password),
            $this->clock->now(),
        );

        try {
            $this->users->insert($account);
        } catch (DuplicateEmailException) {
            // Two registrations for one address arrived together and the unique
            // index settled it. The loser reports the ordinary duplicate message
            // and the winner's account is untouched.
            return self::emailTaken();
        }

        return Result::ok($id);
    }

    /**
     * Check credentials and, when they are right, start a session
     * (Requirements 2.1 to 2.3).
     *
     * @param string      $email     as typed; normalised here, so case and
     *                               surrounding whitespace do not matter
     * @param string      $password  never stored, logged or returned
     * @param string|null $ipAddress the caller address, recorded only as a keyed
     *                               hash in the audit trail
     *
     * @return Result<Session> the new session, whose `contextRole` and
     *                         `dataOwnerId` are frozen copies of the account's;
     *                         or a failure carrying the incorrect-credentials
     *                         message (Requirement 2.2) or, while a lock is
     *                         running, the lockout message (Requirement 2.3)
     */
    public function authenticate(
        string $email,
        string $password,
        DateTimeImmutable $now,
        ?string $ipAddress = null,
    ): Result {
        if ($this->sessions === null || $this->auditLog === null) {
            throw new LogicException(
                'Authentication needs a SessionRepository and an AuditLogRepository; this AuthService has none.'
            );
        }

        $ipHash = $this->ipHasher?->hash($ipAddress);
        $account = $this->users->findByNormalizedEmail(EmailAddress::normalise($email));

        if ($account === null) {
            // No account to count against, so nothing is written but the audit row.
            // The verify against the throwaway hash keeps the timing in line with a
            // real wrong-password attempt.
            $this->burnTime($password);
            $this->audit(AuditAction::SignInFailed, AuditOutcome::Failure, null, $ipHash, $now);

            return self::incorrectCredentials();
        }

        if (self::isLocked($account, $now)) {
            // Requirement 2.3: refused without the password being looked at at all,
            // and at the same cost as any other failure.
            $this->burnTime($password);
            $this->audit(AuditAction::SignInFailed, AuditOutcome::Denied, $account, $ipHash, $now);

            return self::accountLocked();
        }

        // A lock that has run out is spent: the next five failures start from zero
        // rather than from the count that produced it.
        $failuresSoFar = $account->lockedUntil === null ? $account->failedLoginCount : 0;

        // A non-active account - invited and not yet holding a password, or revoked -
        // is refused exactly like a wrong password, so revocation cannot be
        // distinguished from a bad guess (Requirement 7.4).
        $accepted = $this->passwordHasher->verify($password, $account->passwordHash)
            && $account->status === UserStatus::Active;

        if (!$accepted) {
            return $this->recordFailure($account, $failuresSoFar, $ipHash, $now);
        }

        $this->users->resetFailedLogins($account->id, $now);

        $session = Session::start($account, SessionToken::generate(), $now);
        $this->sessions->insert($session);

        $this->audit(AuditAction::SignIn, AuditOutcome::Success, $account, $ipHash, $now);

        return Result::ok($session);
    }

    /**
     * Turn a cookie token into the context of the request it belongs to
     * (Requirement 2.5).
     *
     * A resolution that succeeds slides `last_activity_at` up to `$now`, so the
     * timeout measures idleness rather than session age: a session in continuous use
     * lives indefinitely, and one left alone for half an hour does not.
     *
     * A session found to be idle is terminated here and then, because Requirement 2.5
     * asks for the session to be *ended* rather than merely ignored. That makes the
     * timeout irreversible: the same cookie cannot come back to life if a later
     * request arrives with a clock that disagrees.
     *
     * @param string $token the raw cookie value; a malformed one is simply not signed in
     *
     * @return SecurityContext|null the session's frozen role and owner scope, or null
     *                              when there is no live session behind the token
     */
    public function resolveSession(string $token, DateTimeImmutable $now): ?SecurityContext
    {
        $sessions = $this->sessionStore();

        // Checked for shape before it is hashed and looked up, so junk in a cookie
        // costs one preg_match rather than a query.
        $parsed = SessionToken::tryFromString($token);

        if ($parsed === null) {
            return null;
        }

        $session = $sessions->findById($parsed->id());

        if ($session === null || $session->isTerminated()) {
            return null;
        }

        if (!self::isWithinIdleWindow($session, $now)) {
            $sessions->terminate($session->id, $now);

            return null;
        }

        $sessions->touch($session->id, $now);

        return SecurityContext::forSession($session);
    }

    /**
     * End a session (Requirement 2.4).
     *
     * Termination is recorded against the row, not the cookie, so clearing the cookie
     * is a courtesy: the token a signed-out browser keeps hold of resolves to nothing
     * from here on, on this device or any other. Calling it twice is harmless.
     */
    public function signOut(SessionId $id, DateTimeImmutable $now): void
    {
        $this->sessionStore()->terminate($id, $now);
    }

    /**
     * The whole of "this session may still be used": not signed out, and less than
     * {@see IDLE_TIMEOUT_MINUTES} since its last request.
     *
     * Pure, and public, because it is the predicate Requirements 2.4 and 2.5 are
     * about - stating it once keeps the middleware, the resolver and the tests
     * agreeing on where the boundary sits. Exactly thirty minutes is over the line:
     * the design says `< 30 minutes`.
     */
    public static function isSessionValid(Session $session, DateTimeImmutable $now): bool
    {
        return !$session->isTerminated() && self::isWithinIdleWindow($session, $now);
    }

    /**
     * What a handler tells whoever arrives with a session that has ended
     * (Requirements 2.4, 2.5). Pair it with {@see SessionCookie::clearingHeader()}.
     *
     * @return Result<null>
     */
    public static function sessionEnded(): Result
    {
        return Result::failure(
            self::SESSION_ENDED_ERROR_CODE,
            self::SESSION_ENDED_MESSAGE,
        );
    }

    private static function isWithinIdleWindow(Session $session, DateTimeImmutable $now): bool
    {
        $idleSeconds = $now->getTimestamp() - $session->lastActivityAt->getTimestamp();

        return $idleSeconds < self::IDLE_TIMEOUT_MINUTES * 60;
    }

    private function sessionStore(): SessionRepository
    {
        if ($this->sessions === null) {
            throw new LogicException('Session resolution and sign-out need a SessionRepository; this AuthService has none.');
        }

        return $this->sessions;
    }

    /**
     * Count one failure, lock the account if that was the fifth, and say so in the
     * audit trail (Requirement 2.3).
     *
     * The response is the ordinary incorrect-credentials message even on the failure
     * that sets the lock: the lockout message is only ever the answer to an attempt
     * that meets a lock already in place, so a caller learns nothing about an address
     * they have not already guessed the password of.
     *
     * @return Result<null>
     */
    private function recordFailure(
        UserAccount $account,
        int $failuresSoFar,
        ?string $ipHash,
        DateTimeImmutable $now,
    ): Result {
        $failures = $failuresSoFar + 1;
        $locked = $failures >= self::MAX_FAILED_ATTEMPTS;
        $lockedUntil = $locked
            ? $now->add(new DateInterval('PT' . self::LOCKOUT_MINUTES . 'M'))
            : null;

        $this->users->recordFailedLogin($account->id, $failures, $lockedUntil, $now);
        $this->audit(AuditAction::SignInFailed, AuditOutcome::Failure, $account, $ipHash, $now);

        if ($locked) {
            $this->audit(AuditAction::AccountLocked, AuditOutcome::Failure, $account, $ipHash, $now);
        }

        return self::incorrectCredentials();
    }

    private static function isLocked(UserAccount $account, DateTimeImmutable $now): bool
    {
        return $account->lockedUntil !== null && $account->lockedUntil > $now;
    }

    /**
     * Verify against the throwaway hash so that "no such account" and "locked
     * account" cost what a real verify costs.
     */
    private function burnTime(string $password): void
    {
        $this->timingHash ??= $this->passwordHasher->hash(bin2hex(random_bytes(16)));

        $this->passwordHasher->verify($password, $this->timingHash);
    }

    /**
     * One row per authentication outcome. Identifiers, an action, an outcome and a
     * keyed address hash - no email address, no password, and nothing from a diary
     * entry or milestone can reach this table.
     *
     * A success is recorded under the account's role; a failure or refusal is
     * recorded as anonymous, because no session exists at that point.
     */
    private function audit(
        AuditAction $action,
        AuditOutcome $outcome,
        ?UserAccount $account,
        ?string $ipHash,
        DateTimeImmutable $now,
    ): void {
        $role = $account !== null && $outcome === AuditOutcome::Success
            ? AuditContextRole::fromUserRole($account->role)
            : AuditContextRole::Anonymous;

        $this->auditLog?->record(new AuditLogEntry(
            id: Ulid::generate($this->clock),
            actorUserId: $account?->id,
            contextRole: $role,
            action: $action,
            outcome: $outcome,
            occurredAt: $now,
            targetType: 'session',
            ipHash: $ipHash,
        ));
    }

    /**
     * @return Result<null>
     */
    private static function incorrectCredentials(): Result
    {
        return Result::failure(
            self::INCORRECT_CREDENTIALS_ERROR_CODE,
            self::INCORRECT_CREDENTIALS_MESSAGE,
        );
    }

    /**
     * @return Result<null>
     */
    private static function accountLocked(): Result
    {
        return Result::failure(
            self::ACCOUNT_LOCKED_ERROR_CODE,
            self::ACCOUNT_LOCKED_MESSAGE,
        );
    }

    /**
     * @return Result<null>
     */
    private static function emailTaken(): Result
    {
        return Result::failure(
            self::EMAIL_TAKEN_ERROR_CODE,
            self::EMAIL_TAKEN_MESSAGE,
            [self::EMAIL_FIELD => self::EMAIL_TAKEN_MESSAGE],
        );
    }
}
