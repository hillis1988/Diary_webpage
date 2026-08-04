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
use Diary\Auth\Session;
use Diary\Auth\SessionRepository;
use Diary\Auth\UserAccount;
use Diary\Auth\UserRepository;
use Diary\Support\FixedClock;
use Diary\Support\Result;
use Diary\Tests\Unit\Auth\SqliteAuthTables;
use Eris\Generator;
use Eris\TestTrait;
use LogicException;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Property 5: Authentication outcome follows credential validity.
 *
 * One registered account per run, then three attempts against it: a password that
 * is not the account's, an address no account has, and finally the real password.
 * The claim has two halves, and both are checked against the `sessions` table
 * rather than against the returned object, because "establishes a session" means a
 * row exists that a later request can resolve:
 *
 *  - the two refusals write no session row at all, and answer with the same error
 *    code and the same wording - a caller cannot tell an unknown address from a
 *    wrong password (Requirement 2.2);
 *  - the correct password leaves *exactly one* row whose `context_role` and
 *    `data_owner_id` are the account's own, so access is granted according to the
 *    user's role (Requirement 2.1).
 *
 * The refusals come first deliberately: any session found afterwards has to be the
 * one the successful attempt created, so the count of the whole table settles
 * "exactly one" without a before-and-after difference. Only one of the two refusals
 * can count against the account - the unknown address has no account to count
 * against - so the run never approaches the five consecutive failures that would
 * lock it; that lockout is Property 6, and this property asserts the account is
 * still unlocked before the successful attempt so a lock can never be what makes
 * this test pass or fail.
 *
 * The wrong password is a near miss half the time: one character of the real
 * password replaced. Comparing a prefix, or normalising case, would still refuse an
 * unrelated string.
 *
 * Requirements: 2.1, 2.2.
 */
final class AuthenticationOutcomePropertyTest extends TestCase
{
    use TestTrait;

    private const CLOCK_START = '2025-06-09 14:22:05';

    private const TLDS = ['com', 'net', 'org', 'io', 'co.uk'];

    /** A caller address for the audit trail; hashed, never stored raw. */
    private const IP = '198.51.100.24';

    /** Non-matching passwords built by replacing one character of the real one. */
    private int $nearMisses = 0;

    /** Non-matching passwords generated independently of the real one. */
    private int $unrelatedPasswords = 0;

    protected function setUp(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_sqlite is not loaded.');
        }

        $this->nearMisses = 0;
        $this->unrelatedPasswords = 0;
    }

    // Feature: mental-health-diary, Property 5: Authentication outcome follows credential validity
    public function testAuthenticationOutcomeFollowsCredentialValidity(): void
    {
        $this->limitTo(100)
            ->forAll(
                self::validEmail(),
                self::compliantPassword(),
                self::wrongPasswordRecipe(),
                self::validEmail()
            )
            ->then(function (string $email, string $password, array $recipe, string $otherEmail): void {
                $wrongPassword = self::nonMatching($password, $recipe);
                $unknownEmail = self::distinctFrom($email, $otherEmail);

                // What the generators are supposed to have produced. If any of this
                // stops holding, it fails here rather than turning the run into a
                // test about something else.
                self::assertNotSame($password, $wrongPassword, 'the wrong password must not be the real one');
                self::assertNotSame(
                    EmailAddress::normalise($email),
                    EmailAddress::normalise($unknownEmail),
                    'the unknown address must not be the registered one under a different spelling'
                );

                $recipe['useMutation'] ? ++$this->nearMisses : ++$this->unrelatedPasswords;

                // A fresh store, and therefore a fresh account, per run.
                $pdo = SqliteAuthTables::connection();
                $clock = FixedClock::at(self::CLOCK_START);
                $users = new UserRepository($pdo);
                // The test-only hasher regardless of what this build prefers: real
                // work factors would dominate a hundred runs, and the algorithm is
                // not what this property is about.
                $service = new AuthService(
                    $users,
                    new DefaultPasswordPolicy(),
                    $clock,
                    PasswordHasher::forTests(),
                    new SessionRepository($pdo),
                    new AuditLogRepository($pdo),
                    new IpHasher('property-test-ip-key'),
                );

                self::assertTrue(
                    $service->register($email, $password)->isOk(),
                    sprintf('the account %s should register', var_export($email, true))
                );

                $account = $users->findByNormalizedEmail(EmailAddress::normalise($email));
                self::assertInstanceOf(UserAccount::class, $account);
                self::assertNull(
                    $users->findByNormalizedEmail(EmailAddress::normalise($unknownEmail)),
                    'the unknown address must have no account'
                );

                $base = $clock->now();

                // Half one: neither refusal establishes anything, and both say the
                // same thing.
                $wrongPasswordAttempt = $service->authenticate($email, $wrongPassword, $base, self::IP);
                $this->assertRefused($wrongPasswordAttempt, $pdo, 'a password the account does not have');

                $unknownEmailAttempt = $service->authenticate(
                    $unknownEmail,
                    $password,
                    $base->modify('+37 seconds'),
                    self::IP
                );
                $this->assertRefused($unknownEmailAttempt, $pdo, 'an address no account has');

                self::assertSame(
                    $wrongPasswordAttempt->message(),
                    $unknownEmailAttempt->message(),
                    'a wrong password and an unknown address must be answered identically'
                );
                self::assertSame($wrongPasswordAttempt->errorCode(), $unknownEmailAttempt->errorCode());

                // Lockout cannot be what decides the rest of this run: one failure
                // was counted, four short of the limit, and no lock is in force.
                $counted = $users->findByNormalizedEmail(EmailAddress::normalise($email));
                self::assertInstanceOf(UserAccount::class, $counted);
                self::assertSame(1, $counted->failedLoginCount, 'only the wrong password can be counted');
                self::assertLessThan(AuthService::MAX_FAILED_ATTEMPTS, $counted->failedLoginCount);
                self::assertNull($counted->lockedUntil, 'the account must not be locked at this point');

                // Half two: the real password, and exactly one session frozen to
                // the account.
                $successAt = $base->modify('+74 seconds');
                $accepted = $service->authenticate($email, $password, $successAt, self::IP);

                self::assertTrue(
                    $accepted->isOk(),
                    sprintf(
                        'the account\'s own password must be accepted, got %s',
                        var_export($accepted->message(), true)
                    )
                );

                $session = $accepted->value();
                self::assertInstanceOf(Session::class, $session);
                self::assertTrue($session->userId->equals($account->id));
                self::assertSame($account->role, $session->contextRole, 'the context role is the account\'s role');
                self::assertTrue(
                    $session->dataOwnerId->equals($account->dataOwnerId),
                    'the session\'s data owner is the account\'s data owner'
                );

                $rows = SqliteAuthTables::sessions($pdo);

                self::assertCount(
                    1,
                    $rows,
                    'a successful authentication must leave exactly one session row'
                );
                self::assertSame($session->id->toString(), $rows[0]['id']);
                self::assertSame($account->id->toString(), $rows[0]['user_id']);
                self::assertSame(
                    $account->role->value,
                    $rows[0]['context_role'],
                    'the stored context role must equal the account\'s role'
                );
                self::assertSame(
                    $account->dataOwnerId->toString(),
                    $rows[0]['data_owner_id'],
                    'the stored data owner must equal the account\'s data owner'
                );
                self::assertSame($successAt->format('Y-m-d H:i:s'), $rows[0]['created_at']);
                self::assertNull($rows[0]['terminated_at'], 'the new session must be live');
            });

        // The run only says something about both kinds of non-matching password if
        // it produced both.
        self::assertGreaterThan(0, $this->nearMisses, 'no near-miss password was generated');
        self::assertGreaterThan(0, $this->unrelatedPasswords, 'no unrelated password was generated');
    }

    /**
     * A refusal: no session anywhere, and the one incorrect-credentials answer that
     * names neither the address nor the password.
     *
     * @param Result<mixed> $result
     */
    private function assertRefused(Result $result, PDO $pdo, string $what): void
    {
        self::assertTrue($result->isFailure(), sprintf('%s must be refused', $what));
        self::assertTrue(
            $result->hasErrorCode(AuthService::INCORRECT_CREDENTIALS_ERROR_CODE),
            sprintf('%s must be refused as incorrect credentials, got %s', $what, var_export($result->errorCode(), true))
        );
        self::assertSame(AuthService::INCORRECT_CREDENTIALS_MESSAGE, $result->message());

        try {
            $result->value();
            self::fail(sprintf('%s must not yield a session', $what));
        } catch (LogicException) {
            // Expected: a refusal carries no value.
        }

        self::assertSame(
            [],
            SqliteAuthTables::sessions($pdo),
            sprintf('%s must establish no session', $what)
        );
    }

    /**
     * A password that is not the account's: either the real one with a single
     * character replaced, or one generated without reference to it. The fallback
     * suffix covers the case where the replacement happens to be the character
     * already there.
     *
     * @param array{useMutation: bool, position: int, replacement: string, unrelated: string} $recipe
     */
    private static function nonMatching(string $password, array $recipe): string
    {
        if (!$recipe['useMutation']) {
            return $recipe['unrelated'] === $password ? $recipe['unrelated'] . 'z7' : $recipe['unrelated'];
        }

        // Passwords may carry non-ASCII letters and digits, so this splits by
        // character rather than by byte.
        $characters = mb_str_split($password);
        $characters[$recipe['position'] % max(1, count($characters))] = $recipe['replacement'];
        $mutated = implode('', $characters);

        return $mutated === $password ? $password . 'z7' : $mutated;
    }

    /**
     * An address that is certainly not the registered one, whatever the generator
     * produced. Prefixing the local part keeps it a valid address.
     */
    private static function distinctFrom(string $registered, string $candidate): string
    {
        return EmailAddress::normalise($registered) === EmailAddress::normalise($candidate)
            ? 'nobody' . ltrim($candidate)
            : $candidate;
    }

    /**
     * Which kind of non-matching password to use, and the material for both kinds.
     *
     * @return \Eris\Generator<array{useMutation: bool, position: int, replacement: string, unrelated: string}>
     */
    private static function wrongPasswordRecipe(): \Eris\Generator
    {
        return Generator\map(
            static fn (array $parts): array => [
                'useMutation' => $parts[0],
                'position' => $parts[1],
                'replacement' => $parts[2],
                'unrelated' => $parts[3],
            ],
            Generator\tuple(
                Generator\elements([true, false]),
                Generator\choose(0, 200),
                Generator\elements(['x', 'Q', '5', '_', ' ', 'é']),
                self::compliantPassword()
            )
        );
    }

    /**
     * Addresses Requirement 1.1 admits, varied where sign-in could go wrong: mixed
     * case, dotted and tagged local parts, subdomains and multi-label suffixes.
     */
    private static function validEmail(): \Eris\Generator
    {
        return Generator\map(
            static function (array $parts): string {
                [$local, $tag, $subdomain, $domain, $tld] = $parts;

                $host = $subdomain === '' ? $domain : $subdomain . '.' . $domain;

                return $local . $tag . '@' . $host . '.' . $tld;
            },
            Generator\tuple(
                self::localPart(),
                Generator\oneOf(
                    Generator\constant(''),
                    Generator\map(
                        static fn (string $tag): string => '+' . $tag,
                        self::token(1, 6, 'abcdefghijklmnopqrstuvwxyz0123456789')
                    )
                ),
                Generator\oneOf(
                    Generator\constant(''),
                    self::token(1, 6, 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789')
                ),
                self::token(1, 10, 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789'),
                Generator\elements(self::TLDS)
            )
        );
    }

    /**
     * One or two dot-separated tokens, never with the leading, trailing or doubled
     * dots no address may carry.
     */
    private static function localPart(): \Eris\Generator
    {
        $alphabet = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789_-';

        return Generator\oneOf(
            self::token(1, 12, $alphabet),
            Generator\map(
                static fn (array $tokens): string => implode('.', $tokens),
                Generator\tuple(self::token(1, 8, $alphabet), self::token(1, 8, $alphabet))
            )
        );
    }

    /**
     * A run of $minimum to $maximum characters from $alphabet, always starting with
     * a letter or digit.
     */
    private static function token(int $minimum, int $maximum, string $alphabet): \Eris\Generator
    {
        $head = Generator\elements(str_split('abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789'));
        $rest = Generator\elements(str_split($alphabet));

        return Generator\bind(
            Generator\choose($minimum, $maximum),
            static fn (int $length): \Eris\Generator => Generator\map(
                static fn (array $parts): string => $parts[0] . implode('', $parts[1]),
                Generator\tuple($head, Generator\vector(max(0, $length - 1), $rest))
            )
        );
    }

    /**
     * Passwords the policy accepts: at least twelve characters carrying a letter and
     * a digit, placed at generated positions so compliance is not tied to a fixed
     * shape.
     */
    private static function compliantPassword(): \Eris\Generator
    {
        $filler = Generator\elements(str_split('abcdefghijklmnopqrstuvwxyzABCDEFZ0123456789 !-_.@#'));

        return Generator\bind(
            Generator\choose(DefaultPasswordPolicy::MINIMUM_LENGTH, 24),
            static fn (int $length): \Eris\Generator => Generator\map(
                static function (array $parts) use ($length): string {
                    /** @var array{0: list<string>, 1: string, 2: string, 3: int, 4: int} $parts */
                    [$characters, $letter, $digit, $digitOffset, $letterOffset] = $parts;

                    $digitAt = $digitOffset % $length;
                    $letterAt = ($digitAt + 1 + ($letterOffset % max(1, $length - 1))) % $length;

                    $characters[$digitAt] = $digit;
                    $characters[$letterAt] = $letter;

                    return implode('', $characters);
                },
                Generator\tuple(
                    Generator\vector($length, $filler),
                    Generator\elements(['a', 'Q', 'z', 'é', 'Ж']),
                    Generator\elements(['0', '4', '9', '٣']),
                    Generator\choose(0, 100),
                    Generator\choose(0, 100)
                )
            )
        );
    }
}
