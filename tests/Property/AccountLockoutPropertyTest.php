<?php

declare(strict_types=1);

namespace Diary\Tests\Property;

use DateInterval;
use DateTimeImmutable;
use Diary\Auth\AuditAction;
use Diary\Auth\AuditLogRepository;
use Diary\Auth\AuthService;
use Diary\Auth\DefaultPasswordPolicy;
use Diary\Auth\IpHasher;
use Diary\Auth\PasswordHasher;
use Diary\Auth\Session;
use Diary\Auth\SessionRepository;
use Diary\Auth\UserAccount;
use Diary\Auth\UserRepository;
use Diary\Support\FixedClock;
use Diary\Tests\Unit\Auth\SqliteAuthTables;
use Eris\Generator;
use Eris\TestTrait;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Property 6: Lockout after five consecutive failures lasts fifteen minutes.
 *
 * Each run drives one account through a whole lockout cycle on a clock that only
 * ever moves because the test moves it - nothing here sleeps, and every instant
 * handed to the service is an explicit value:
 *
 *   1. between one and four failures, then a success: the success has to put the
 *      consecutive count back to zero, so the failures that follow start again
 *      from one rather than from where the earlier run left off;
 *   2. five failures: after each of the first four the account has to be unlocked,
 *      and the fifth has to set `locked_until` to exactly fifteen minutes after the
 *      instant of that fifth failure;
 *   3. one attempt with the *correct* password somewhere inside the window: it has
 *      to be refused with the lockout message, start no session, and leave the
 *      lock's expiry exactly where it was - a live lock must not be extended by
 *      being met;
 *   4. one attempt with the correct password at or after the expiry: it has to
 *      succeed, start a session, and clear both the counter and the lock.
 *
 * The gaps between attempts are generated, so the fifteen minutes is measured from
 * the fifth failure and from nothing else, and the expiry instant itself (offset
 * exactly nine hundred seconds) is drawn often, because "until" is where an
 * off-by-one hides.
 *
 * Requirements: 2.3.
 */
final class AccountLockoutPropertyTest extends TestCase
{
    use TestTrait;

    /** Where every run's clock starts; moved only by this test, never by sleeping. */
    private const START = '2025-06-01 07:00:00';

    private const IP = '198.51.100.24';

    /** Characters the generated local part and passwords are built from. */
    private const CHARACTERS = ['a', 'b', 'e', 'k', 'm', 'r', 'z', '0', '3', '7', '9'];

    /** How many runs saw the expiry instant itself rather than a later one. */
    private int $exactExpiryRuns = 0;

    /** How many runs each count of pre-success failures produced. */
    private int $maximumEarlyFailures = 0;

    protected function setUp(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_sqlite is not loaded.');
        }

        $this->exactExpiryRuns = 0;
        $this->maximumEarlyFailures = 0;
    }

    // Feature: mental-health-diary, Property 6: Lockout after five consecutive failures lasts fifteen minutes
    public function testLockoutAfterFiveConsecutiveFailuresLastsFifteenMinutes(): void
    {
        $this->limitTo(100)
            ->forAll(self::scenario())
            ->then(function (array $scenario): void {
                [
                    'email' => $email,
                    'password' => $password,
                    'wrongPassword' => $wrongPassword,
                    'earlyFailures' => $earlyFailures,
                    'gapSeconds' => $gapSeconds,
                    'liveOffsetSeconds' => $liveOffsetSeconds,
                    'expiredOffsetSeconds' => $expiredOffsetSeconds,
                ] = $scenario;

                self::assertNotSame($password, $wrongPassword);
                self::assertLessThan(AuthService::MAX_FAILED_ATTEMPTS, $earlyFailures);
                self::assertLessThan(self::lockoutSeconds(), $liveOffsetSeconds);
                self::assertGreaterThanOrEqual(self::lockoutSeconds(), $expiredOffsetSeconds);

                $clock = FixedClock::at(self::START);
                $pdo = SqliteAuthTables::connection();
                $users = new UserRepository($pdo);
                $service = new AuthService(
                    $users,
                    new DefaultPasswordPolicy(),
                    $clock,
                    // The test-only hasher whatever this build prefers: real work
                    // factors would dominate a run that verifies a dozen times per
                    // case, and this property is about the clock.
                    PasswordHasher::forTests(),
                    new SessionRepository($pdo),
                    new AuditLogRepository($pdo),
                    new IpHasher('property-test-ip-key'),
                );

                self::assertTrue(
                    $service->register($email, $password)->isOk(),
                    'the account under test must register: ' . self::describe($scenario)
                );

                // 1. Failures short of the fifth, then a success that clears them.
                for ($attempt = 1; $attempt <= $earlyFailures; $attempt++) {
                    $clock->advanceSeconds($gapSeconds);
                    $refusal = $service->authenticate($email, $wrongPassword . $attempt, $clock->now(), self::IP);

                    self::assertTrue(
                        $refusal->hasErrorCode(AuthService::INCORRECT_CREDENTIALS_ERROR_CODE),
                        sprintf('failure %d before the reset must be an ordinary refusal: %s', $attempt, self::describe($scenario))
                    );

                    $account = self::account($users, $email);
                    self::assertSame($attempt, $account->failedLoginCount);
                    self::assertNull(
                        $account->lockedUntil,
                        sprintf('%d failures must not lock the account: %s', $attempt, self::describe($scenario))
                    );
                }

                $clock->advanceSeconds($gapSeconds);
                $reset = $service->authenticate($email, $password, $clock->now(), self::IP);

                self::assertTrue($reset->isOk(), 'the correct password must be accepted: ' . self::describe($scenario));
                self::assertInstanceOf(Session::class, $reset->value());

                $afterReset = self::account($users, $email);
                self::assertSame(
                    0,
                    $afterReset->failedLoginCount,
                    'a success must put the consecutive count back to zero: ' . self::describe($scenario)
                );
                self::assertNull($afterReset->lockedUntil);
                self::assertCount(1, SqliteAuthTables::sessions($pdo));

                // 2. Five consecutive failures, counted from zero again.
                $fifthFailureAt = null;

                for ($attempt = 1; $attempt <= AuthService::MAX_FAILED_ATTEMPTS; $attempt++) {
                    $clock->advanceSeconds($gapSeconds);
                    $now = $clock->now();
                    $refusal = $service->authenticate($email, $wrongPassword . $attempt, $now, self::IP);

                    // Even the failure that sets the lock answers with the ordinary
                    // message: the lockout wording is only ever the answer to an
                    // attempt that meets a lock already in place.
                    self::assertTrue(
                        $refusal->hasErrorCode(AuthService::INCORRECT_CREDENTIALS_ERROR_CODE),
                        sprintf('failure %d must be an ordinary refusal: %s', $attempt, self::describe($scenario))
                    );

                    $account = self::account($users, $email);
                    self::assertSame(
                        $attempt,
                        $account->failedLoginCount,
                        sprintf('the count must follow the run of failures: %s', self::describe($scenario))
                    );

                    if ($attempt < AuthService::MAX_FAILED_ATTEMPTS) {
                        self::assertNull(
                            $account->lockedUntil,
                            sprintf('%d consecutive failures must leave the account unlocked: %s', $attempt, self::describe($scenario))
                        );

                        continue;
                    }

                    $fifthFailureAt = $now;

                    self::assertNotNull(
                        $account->lockedUntil,
                        'the fifth consecutive failure must lock the account: ' . self::describe($scenario)
                    );
                    self::assertSame(
                        UserRepository::formatDateTime(self::lockExpiryAfter($now)),
                        UserRepository::formatDateTime($account->lockedUntil),
                        'the lock must expire exactly fifteen minutes after the fifth failure: '
                        . self::describe($scenario)
                    );
                }

                self::assertNotNull($fifthFailureAt);
                $expiry = self::lockExpiryAfter($fifthFailureAt);

                self::assertSame(
                    1,
                    self::auditCount($pdo, AuditAction::AccountLocked),
                    'the lock must be recorded once in the audit trail: ' . self::describe($scenario)
                );
                self::assertCount(1, SqliteAuthTables::sessions($pdo), 'no failure may start a session');

                // 3. Inside the window the correct password is refused all the same,
                //    and meeting the lock does not push its expiry out.
                $clock->set($fifthFailureAt->modify(sprintf('+%d seconds', $liveOffsetSeconds)));
                $duringLock = $service->authenticate($email, $password, $clock->now(), self::IP);

                self::assertTrue(
                    $duringLock->hasErrorCode(AuthService::ACCOUNT_LOCKED_ERROR_CODE),
                    'a live lock must refuse even the correct password: ' . self::describe($scenario)
                );
                self::assertSame(AuthService::ACCOUNT_LOCKED_MESSAGE, $duringLock->message());
                self::assertCount(
                    1,
                    SqliteAuthTables::sessions($pdo),
                    'an attempt meeting a live lock must start no session: ' . self::describe($scenario)
                );

                $stillLocked = self::account($users, $email);
                self::assertSame(AuthService::MAX_FAILED_ATTEMPTS, $stillLocked->failedLoginCount);
                self::assertSame(
                    UserRepository::formatDateTime($expiry),
                    UserRepository::formatDateTime($stillLocked->lockedUntil),
                    'meeting the lock must not extend it: ' . self::describe($scenario)
                );

                // 4. At or after the expiry the same password gets in.
                $clock->set($fifthFailureAt->modify(sprintf('+%d seconds', $expiredOffsetSeconds)));
                $afterExpiry = $service->authenticate($email, $password, $clock->now(), self::IP);

                self::assertTrue(
                    $afterExpiry->isOk(),
                    'the lock must be over fifteen minutes after the fifth failure: ' . self::describe($scenario)
                );
                self::assertInstanceOf(Session::class, $afterExpiry->value());
                self::assertCount(
                    2,
                    SqliteAuthTables::sessions($pdo),
                    'the sign-in after the lock must start a session: ' . self::describe($scenario)
                );

                $unlocked = self::account($users, $email);
                self::assertSame(0, $unlocked->failedLoginCount, 'a success resets the count');
                self::assertNull($unlocked->lockedUntil, 'a success clears the lock');

                if ($expiredOffsetSeconds === self::lockoutSeconds()) {
                    ++$this->exactExpiryRuns;
                }

                $this->maximumEarlyFailures = max($this->maximumEarlyFailures, $earlyFailures);
            });

        // The run only says what it claims if it covered the boundary instant and a
        // reset from a count as high as four.
        self::assertGreaterThan(
            0,
            $this->exactExpiryRuns,
            'no run tried the expiry instant itself'
        );
        self::assertSame(
            AuthService::MAX_FAILED_ATTEMPTS - 1,
            $this->maximumEarlyFailures,
            'no run reset the count from four failures'
        );
    }

    /**
     * One account, one right password, one wrong one, how many failures precede the
     * reset, the gap between attempts, an instant inside the lock and an instant at
     * or after its expiry - both measured from the fifth failure.
     *
     * @return \Eris\Generator<array{email: string, password: string, wrongPassword: string,
     *                              earlyFailures: int, gapSeconds: int, liveOffsetSeconds: int,
     *                              expiredOffsetSeconds: int}>
     */
    private static function scenario(): \Eris\Generator
    {
        return Generator\map(
            static fn (array $parts): array => [
                'email' => $parts[0],
                'password' => $parts[1],
                'wrongPassword' => $parts[2],
                'earlyFailures' => $parts[3],
                'gapSeconds' => $parts[4],
                'liveOffsetSeconds' => $parts[5],
                'expiredOffsetSeconds' => $parts[6],
            ],
            Generator\tuple(
                self::email(),
                self::password('a1'),
                self::password('b2'),
                // Fewer than five, so the success that follows is the thing that
                // clears them rather than a lock expiring.
                Generator\choose(1, AuthService::MAX_FAILED_ATTEMPTS - 1),
                Generator\choose(1, 240),
                // Anywhere strictly inside the fifteen minutes, the instant of the
                // fifth failure included.
                Generator\choose(0, self::lockoutSeconds() - 1),
                Generator\frequency(
                    // `locked_until` is compared with `>`, so the expiry instant
                    // itself is already unlocked; it gets its own weight.
                    [1, Generator\constant(self::lockoutSeconds())],
                    [2, Generator\choose(self::lockoutSeconds(), self::lockoutSeconds() + 7_200)]
                )
            )
        );
    }

    /**
     * A valid, lowercase address. The mailbox is the only account in its own
     * in-memory database, so no two runs can collide.
     */
    private static function email(): \Eris\Generator
    {
        return Generator\map(
            static fn (array $characters): string => implode('', $characters) . '@example.com',
            Generator\vector(8, Generator\elements(self::CHARACTERS))
        );
    }

    /**
     * A password the policy accepts: at least twelve characters carrying a letter
     * and a digit. The suffix supplies both classes and keeps the right and wrong
     * passwords of a run distinct.
     */
    private static function password(string $suffix): \Eris\Generator
    {
        return Generator\map(
            static fn (array $characters): string => implode('', $characters) . $suffix,
            Generator\vector(14, Generator\elements(array_merge(self::CHARACTERS, ['-', '.', ' '])))
        );
    }

    private static function lockoutSeconds(): int
    {
        return AuthService::LOCKOUT_MINUTES * 60;
    }

    private static function lockExpiryAfter(DateTimeImmutable $failedAt): DateTimeImmutable
    {
        return $failedAt->add(new DateInterval('PT' . AuthService::LOCKOUT_MINUTES . 'M'));
    }

    private static function account(UserRepository $users, string $email): UserAccount
    {
        $account = $users->findByEmail($email);

        self::assertInstanceOf(UserAccount::class, $account, 'the account under test must still be there');

        return $account;
    }

    private static function auditCount(PDO $pdo, AuditAction $action): int
    {
        $rows = 0;

        foreach (SqliteAuthTables::auditLog($pdo) as $row) {
            if ($row['action'] === $action->value) {
                ++$rows;
            }
        }

        return $rows;
    }

    /**
     * @param array<string, mixed> $scenario
     */
    private static function describe(array $scenario): string
    {
        return sprintf(
            'email %s, %d failures before the reset, %ds between attempts, lock met at +%ds, retried at +%ds',
            (string) $scenario['email'],
            (int) $scenario['earlyFailures'],
            (int) $scenario['gapSeconds'],
            (int) $scenario['liveOffsetSeconds'],
            (int) $scenario['expiredOffsetSeconds']
        );
    }
}
