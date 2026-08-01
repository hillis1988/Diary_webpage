<?php

declare(strict_types=1);

namespace Diary\Tests\Property;

use DateTimeImmutable;
use Diary\Auth\AuditLogRepository;
use Diary\Auth\AuthService;
use Diary\Auth\DefaultPasswordPolicy;
use Diary\Auth\EmailAddress;
use Diary\Auth\IpHasher;
use Diary\Auth\PasswordHasher;
use Diary\Auth\SecurityContext;
use Diary\Auth\Session;
use Diary\Auth\SessionId;
use Diary\Auth\SessionRepository;
use Diary\Auth\SessionToken;
use Diary\Auth\UserAccount;
use Diary\Auth\UserRepository;
use Diary\Storage\SqlTimestamp;
use Diary\Support\FixedClock;
use Diary\Tests\Unit\Auth\SqliteAuthTables;
use Eris\Generator;
use Eris\TestTrait;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Property 7: A session is valid only while it is neither signed out nor idle for
 * thirty minutes.
 *
 * Every instant in this test is a value the test chose. Nothing sleeps: the clock
 * is a {@see FixedClock} and each resolution is handed an explicit
 * `DateTimeImmutable`, so "half an hour of inactivity" is arithmetic rather than
 * waiting.
 *
 * Each run signs in once - bcrypt is the only expensive thing here, so it happens
 * a single time - and then drives four cheap phases against sessions of that one
 * account:
 *
 *   1. the boundary, four times over: at 29:59 and at a generated instant strictly
 *      inside the window the session resolves, and at 30:00 and a generated instant
 *      beyond it the session does not. Thirty minutes exactly is over the line, and
 *      that is the pair of assertions an off-by-one would break;
 *   2. continuous use: a generated stride under half an hour, repeated enough times
 *      that the session's total age passes thirty minutes several times over. Every
 *      resolution has to succeed, because each one slides `last_activity_at`
 *      forward - the timeout measures idleness, not age;
 *   3. a generated sequence of idle gaps against the signed-in session, with an
 *      optional sign-out somewhere in it. A model kept beside the service says, for
 *      each step, whether a context is expected: not signed out, and less than
 *      thirty minutes since the last *successful* resolution. The idle window
 *      moving is what makes this a sequence rather than a single check, since after
 *      a success the next gap is measured from the new instant;
 *   4. after sign-out, the same token at several later instants - none of them may
 *      resolve, and a second sign-out may not move the moment access ended.
 *
 * Three things are asserted around every resolution, not just the return value:
 * the pure predicate {@see AuthService::isSessionValid()} read from the stored row
 * agrees with the model; a success slides `last_activity_at` to exactly the instant
 * of the request; and a refusal leaves the row terminated, at the instant the
 * timeout was noticed or the instant of the sign-out, whichever came first. The
 * last of those is what makes an expiry irreversible - a later request with a
 * disagreeing clock cannot bring the cookie back.
 *
 * Requirements: 2.4, 2.5.
 */
final class SessionValidityPropertyTest extends TestCase
{
    use TestTrait;

    /** Where every run's clock starts. */
    private const START = '2025-06-14 09:15:00';

    private const IP = '203.0.113.42';

    /** Characters the generated address and password are built from. */
    private const CHARACTERS = ['a', 'c', 'h', 'k', 'n', 'r', 't', 'z', '2', '5', '8'];

    /**
     * Cookie values of the sessions this test started, by identifier. A token cannot
     * be recovered from a stored session - that is the point of hashing it into the
     * identifier - so the test keeps its own copies.
     *
     * @var array<string, string>
     */
    private static array $tokens = [];

    /** How many runs saw a sign-out end a session that was still live. */
    private int $liveSignOutRuns = 0;

    /** How many runs saw the idle timeout end a session mid-sequence. */
    private int $idleTimeoutRuns = 0;

    /** The longest continuously-used session any run kept alive, in seconds. */
    private int $longestContinuousUse = 0;

    private ?AuthService $service = null;

    private ?SessionRepository $sessions = null;

    private ?PDO $pdo = null;

    private ?UserAccount $account = null;

    protected function setUp(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_sqlite is not loaded.');
        }

        $this->liveSignOutRuns = 0;
        $this->idleTimeoutRuns = 0;
        $this->longestContinuousUse = 0;
    }

    // Feature: mental-health-diary, Property 7: A session is valid only while it is neither signed out nor idle for thirty minutes
    public function testSessionIsValidOnlyWhileNeitherSignedOutNorIdleForThirtyMinutes(): void
    {
        $this->limitTo(100)
            ->forAll(self::scenario())
            ->then(function (array $scenario): void {
                [
                    'email' => $email,
                    'password' => $password,
                    'gaps' => $gaps,
                    'signOutStep' => $signOutStep,
                    'liveOffsetSeconds' => $liveOffsetSeconds,
                    'idleOffsetSeconds' => $idleOffsetSeconds,
                    'strideSeconds' => $strideSeconds,
                    'strideCount' => $strideCount,
                ] = $scenario;

                // What the generators are supposed to have produced.
                self::assertLessThan(self::timeoutSeconds(), $liveOffsetSeconds);
                self::assertGreaterThanOrEqual(self::timeoutSeconds(), $idleOffsetSeconds);
                self::assertLessThan(self::timeoutSeconds(), $strideSeconds);
                self::assertGreaterThan(self::timeoutSeconds(), $strideSeconds * $strideCount);

                $clock = FixedClock::at(self::START);
                $pdo = SqliteAuthTables::connection();
                $users = new UserRepository($pdo);
                $sessions = new SessionRepository($pdo);
                $service = new AuthService(
                    $users,
                    new DefaultPasswordPolicy(),
                    $clock,
                    // bcrypt whatever this build prefers: the property is about the
                    // clock, and Argon2id's memory cost would dominate the run.
                    new PasswordHasher(PASSWORD_BCRYPT),
                    $sessions,
                    new AuditLogRepository($pdo),
                    new IpHasher('property-test-ip-key'),
                );

                self::assertTrue($service->register($email, $password)->isOk(), 'the account must register');

                $account = $users->findByNormalizedEmail(EmailAddress::normalise($email));
                self::assertInstanceOf(UserAccount::class, $account);

                $base = $clock->now();

                // The one sign-in of the run. Every other session below is started
                // from the same account without touching the hasher again.
                $signedIn = $service->authenticate($email, $password, $base, self::IP);
                self::assertTrue($signedIn->isOk(), 'the correct password must be accepted');

                $session = $signedIn->value();
                self::assertInstanceOf(Session::class, $session);

                $issued = $session->issuedToken();
                self::assertInstanceOf(SessionToken::class, $issued, 'a fresh session carries its cookie token');

                self::$tokens[$session->id->toString()] = $issued->value();

                $this->service = $service;
                $this->sessions = $sessions;
                $this->pdo = $pdo;
                $this->account = $account;

                // 1. The boundary. Both fixed instants every run, so an off-by-one
                //    cannot hide behind an unlucky draw, plus the generated pair.
                foreach ([self::timeoutSeconds() - 1, $liveOffsetSeconds] as $offset) {
                    $live = $this->startSession($base);
                    $this->expectContext(
                        $live,
                        $base->modify(sprintf('+%d seconds', $offset)),
                        sprintf('idle for %ds, short of the timeout', $offset)
                    );
                }

                foreach ([self::timeoutSeconds(), $idleOffsetSeconds] as $offset) {
                    $expired = $this->startSession($base);
                    $expiredAt = $base->modify(sprintf('+%d seconds', $offset));
                    $this->expectNoContext(
                        $expired,
                        $expiredAt,
                        $expiredAt,
                        sprintf('idle for %ds, at or past the timeout', $offset)
                    );
                }

                // 2. Continuous use. Total age passes half an hour well over, and
                //    every single resolution still has to succeed.
                $continuous = $this->startSession($base);
                $at = $base;

                for ($step = 1; $step <= $strideCount; $step++) {
                    $at = $at->modify(sprintf('+%d seconds', $strideSeconds));
                    $this->expectContext(
                        $continuous,
                        $at,
                        sprintf('used every %ds, %ds after it started', $strideSeconds, $strideSeconds * $step)
                    );
                }

                $this->longestContinuousUse = max(
                    $this->longestContinuousUse,
                    $at->getTimestamp() - $base->getTimestamp()
                );

                // 3. The generated sequence against the signed-in session. The model
                //    is the property, stated once: valid exactly while neither
                //    signed out nor idle for thirty minutes.
                $token = $issued->value();
                $lastActivityAt = $base;
                $terminatedAt = null;
                $now = $base;

                foreach ($gaps as $step => $gap) {
                    $now = $now->modify(sprintf('+%d seconds', $gap));

                    if ($step === $signOutStep) {
                        $wasLive = $terminatedAt === null;
                        $service->signOut($session->id, $now);

                        // Idempotent: a row already terminated keeps the moment it
                        // first was, so only a live session records this instant.
                        $terminatedAt ??= $now;

                        if ($wasLive) {
                            ++$this->liveSignOutRuns;
                        }
                    }

                    $idleSeconds = $now->getTimestamp() - $lastActivityAt->getTimestamp();
                    $expected = $terminatedAt === null && $idleSeconds < self::timeoutSeconds();
                    $what = sprintf(
                        'step %d: %ds idle, %s',
                        $step,
                        $idleSeconds,
                        $terminatedAt === null ? 'not signed out' : 'signed out'
                    );

                    if ($expected) {
                        $this->expectContext($session->id, $now, $what);
                        // The success moved the window, so the next gap is measured
                        // from here rather than from where the session started.
                        $lastActivityAt = $now;

                        continue;
                    }

                    if ($terminatedAt === null) {
                        // The timeout is noticed now, and ends the session now.
                        $terminatedAt = $now;
                        ++$this->idleTimeoutRuns;
                    }

                    $this->expectNoContext($session->id, $now, $terminatedAt, $what);
                }

                // 4. Signed out, and never resolving again. If the sequence has not
                //    already ended the session, this does.
                if ($terminatedAt === null) {
                    $now = $now->modify('+45 seconds');
                    $service->signOut($session->id, $now);
                    $terminatedAt = $now;
                    ++$this->liveSignOutRuns;
                }

                foreach ([1, 60, 1_799, 1_800, 86_400] as $later) {
                    $this->expectNoContext(
                        $session->id,
                        $now->modify(sprintf('+%d seconds', $later)),
                        $terminatedAt,
                        sprintf('%ds after the session ended', $later)
                    );
                }

                // A second sign-out cannot move the record of when access ended.
                $service->signOut($session->id, $now->modify('+2 hours'));
                self::assertSame(
                    SqlTimestamp::format($terminatedAt),
                    self::row($pdo, $session->id)['terminated_at'],
                    'signing out twice must not move the moment access ended'
                );
                self::assertNull(
                    $service->resolveSession($token, $now->modify('+2 hours')),
                    'the token of a signed-out session must never resolve again'
                );
            });

        // The run only says what it claims if the generated sequences produced both
        // ways a session can end, and kept one alive well past the timeout.
        self::assertGreaterThan(0, $this->idleTimeoutRuns, 'no run let a session go idle past the timeout');
        self::assertGreaterThan(0, $this->liveSignOutRuns, 'no run signed a live session out');
        self::assertGreaterThan(
            self::timeoutSeconds(),
            $this->longestContinuousUse,
            'no run kept a session alive for longer than the timeout by using it'
        );
    }

    /**
     * A resolution that must succeed: an owner context for the account under test,
     * with `last_activity_at` slid up to this very instant and the row still live.
     */
    private function expectContext(SessionId $id, DateTimeImmutable $now, string $what): void
    {
        $service = $this->service;
        $sessions = $this->sessions;
        $pdo = $this->pdo;
        $account = $this->account;

        self::assertNotNull($service);
        self::assertNotNull($sessions);
        self::assertNotNull($pdo);
        self::assertNotNull($account);

        $stored = $sessions->findById($id);
        self::assertInstanceOf(Session::class, $stored);
        self::assertTrue(
            AuthService::isSessionValid($stored, $now),
            sprintf('the stored row must be valid - %s', $what)
        );

        $context = $service->resolveSession(self::tokenOf($id), $now);

        self::assertInstanceOf(
            SecurityContext::class,
            $context,
            sprintf('the session must resolve - %s', $what)
        );
        self::assertTrue($context->isAuthenticated(), sprintf('the context must be signed in - %s', $what));
        self::assertTrue($context->isOwner(), sprintf('the account is the owner - %s', $what));
        self::assertNotNull($context->userId);
        self::assertNotNull($context->dataOwnerId);
        self::assertTrue($context->userId->equals($account->id));
        self::assertTrue($context->dataOwnerId->equals($account->dataOwnerId));

        $row = self::row($pdo, $id);
        self::assertSame(
            SqlTimestamp::format($now),
            $row['last_activity_at'],
            sprintf('a successful resolution must slide the idle window to now - %s', $what)
        );
        self::assertNull($row['terminated_at'], sprintf('a resolved session must stay live - %s', $what));
    }

    /**
     * A resolution that must fail: no context at all, and a row terminated at
     * $expectedTerminatedAt - the sign-out instant, or the instant the timeout was
     * first noticed.
     */
    private function expectNoContext(
        SessionId $id,
        DateTimeImmutable $now,
        DateTimeImmutable $expectedTerminatedAt,
        string $what,
    ): void {
        $service = $this->service;
        $sessions = $this->sessions;
        $pdo = $this->pdo;

        self::assertNotNull($service);
        self::assertNotNull($sessions);
        self::assertNotNull($pdo);

        $stored = $sessions->findById($id);
        self::assertInstanceOf(Session::class, $stored);
        self::assertFalse(
            AuthService::isSessionValid($stored, $now),
            sprintf('the stored row must not be valid - %s', $what)
        );

        $before = self::row($pdo, $id)['last_activity_at'];

        self::assertNull(
            $service->resolveSession(self::tokenOf($id), $now),
            sprintf('the session must not resolve - %s', $what)
        );

        $row = self::row($pdo, $id);
        self::assertSame(
            SqlTimestamp::format($expectedTerminatedAt),
            $row['terminated_at'],
            sprintf('the session must be terminated at the expected instant - %s', $what)
        );
        self::assertSame(
            $before,
            $row['last_activity_at'],
            sprintf('a refused resolution must not slide the idle window - %s', $what)
        );
    }

    /**
     * Start another session for the account under test at $at, without going through
     * the password hasher again, and remember its cookie token.
     */
    private function startSession(DateTimeImmutable $at): SessionId
    {
        $account = $this->account;
        $sessions = $this->sessions;

        self::assertNotNull($account);
        self::assertNotNull($sessions);

        $session = Session::start($account, SessionToken::generate(), $at);
        $sessions->insert($session);

        $token = $session->issuedToken();
        self::assertInstanceOf(SessionToken::class, $token);

        self::$tokens[$session->id->toString()] = $token->value();

        return $session->id;
    }

    private static function tokenOf(SessionId $id): string
    {
        $token = self::$tokens[$id->toString()] ?? null;

        self::assertIsString($token, 'the token of a session this test started must be known');

        return $token;
    }

    /**
     * @return array<string, mixed>
     */
    private static function row(PDO $pdo, SessionId $id): array
    {
        foreach (SqliteAuthTables::sessions($pdo) as $row) {
            if ($row['id'] === $id->toString()) {
                return $row;
            }
        }

        self::fail(sprintf('no session row %s', $id->toString()));
    }

    private static function timeoutSeconds(): int
    {
        return AuthService::IDLE_TIMEOUT_MINUTES * 60;
    }

    /**
     * One account, a sequence of idle gaps, an optional step to sign out at, an
     * instant inside the window and one at or past it, and a stride short of the
     * timeout repeated enough times to outlive it.
     *
     * @return \Eris\Generator<array{email: string, password: string, gaps: list<int>,
     *                              signOutStep: int|null, liveOffsetSeconds: int,
     *                              idleOffsetSeconds: int, strideSeconds: int, strideCount: int}>
     */
    private static function scenario(): \Eris\Generator
    {
        return Generator\map(
            static function (array $parts): array {
                /** @var array{0: string, 1: string, 2: list<int>, 3: int, 4: int, 5: int, 6: int, 7: int} $parts */
                $gaps = $parts[2];
                $signOut = $parts[3];

                return [
                    'email' => $parts[0],
                    'password' => $parts[1],
                    'gaps' => $gaps,
                    // A negative draw, or one past the end of the sequence, means no
                    // sign-out inside it; the run signs out afterwards instead.
                    'signOutStep' => $signOut >= 0 && $signOut < count($gaps) ? $signOut : null,
                    'liveOffsetSeconds' => $parts[4],
                    'idleOffsetSeconds' => $parts[5],
                    'strideSeconds' => $parts[6],
                    'strideCount' => $parts[7],
                ];
            },
            Generator\tuple(
                self::email(),
                self::password(),
                self::gaps(),
                Generator\choose(-2, 6),
                // Strictly inside the window, the boundary second included.
                Generator\frequency(
                    [1, Generator\constant(self::timeoutSeconds() - 1)],
                    [2, Generator\choose(1, self::timeoutSeconds() - 1)]
                ),
                // At or past it: thirty minutes exactly is already too long.
                Generator\frequency(
                    [1, Generator\constant(self::timeoutSeconds())],
                    [2, Generator\choose(self::timeoutSeconds(), self::timeoutSeconds() * 4)]
                ),
                Generator\choose(900, self::timeoutSeconds() - 1),
                Generator\choose(3, 6)
            )
        );
    }

    /**
     * Two to six idle gaps. Most fall short of the timeout, so the sliding window
     * gets exercised rather than the first gap ending the run; the rest sit on or
     * past the boundary.
     *
     * @return \Eris\Generator<list<int>>
     */
    private static function gaps(): \Eris\Generator
    {
        $gap = Generator\frequency(
            [4, Generator\choose(1, self::timeoutSeconds() - 1)],
            [1, Generator\constant(self::timeoutSeconds() - 1)],
            [1, Generator\constant(self::timeoutSeconds())],
            [1, Generator\choose(self::timeoutSeconds(), self::timeoutSeconds() * 3)]
        );

        return Generator\bind(
            Generator\choose(2, 6),
            static fn (int $length): \Eris\Generator => Generator\vector($length, $gap)
        );
    }

    /**
     * A valid, lowercase address. It is the only account in its own in-memory
     * database, so no two runs can collide.
     */
    private static function email(): \Eris\Generator
    {
        return Generator\map(
            static fn (array $characters): string => implode('', $characters) . '@example.net',
            Generator\vector(8, Generator\elements(self::CHARACTERS))
        );
    }

    /**
     * A password the policy accepts: at least twelve characters carrying a letter
     * and a digit, both supplied by the suffix.
     */
    private static function password(): \Eris\Generator
    {
        return Generator\map(
            static fn (array $characters): string => implode('', $characters) . 'a7',
            Generator\vector(14, Generator\elements(array_merge(self::CHARACTERS, ['-', '.', ' '])))
        );
    }
}
