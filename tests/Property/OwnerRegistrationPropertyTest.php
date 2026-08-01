<?php

declare(strict_types=1);

namespace Diary\Tests\Property;

use Diary\Auth\AuthService;
use Diary\Auth\DefaultPasswordPolicy;
use Diary\Auth\EmailAddress;
use Diary\Auth\PasswordHasher;
use Diary\Auth\UserAccount;
use Diary\Auth\UserId;
use Diary\Auth\UserRepository;
use Diary\Auth\UserRole;
use Diary\Auth\UserStatus;
use Diary\Support\FixedClock;
use Diary\Tests\Unit\Auth\SqliteUsersTable;
use Eris\Generator;
use Eris\TestTrait;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Property 2: Valid registration creates an Owner_Role account.
 *
 * Registration is driven through the real Auth_Service against a real `users`
 * table - unique index, ENUM check constraints and the self-referential foreign
 * key on `data_owner_id` all in force - and every claim is then checked against
 * what the database actually holds rather than against the object handed to the
 * repository. A mapping slip that wrote the wrong role, or pointed
 * `data_owner_id` somewhere else, would survive an in-memory check.
 *
 * The three parts of the claim, in the order they are asserted:
 *
 *  1. *exactly one* account - the table holds a single row afterwards, so
 *     registration neither writes a second row nor leaves a partial one;
 *  2. *holding the Owner_Role whose data owner is itself* - the stored `role`
 *     is `owner` and `data_owner_id` is the row's own id;
 *  3. *retrievable by the normalised form of that email* - a lookup by the
 *     trimmed, lowercased address returns that same account.
 *
 * Case and whitespace variants are generated because normalisation is what makes
 * claim 3 meaningful, but this property only asks that the account come back
 * under its normalised form; that two variants of one address collide as a
 * duplicate is Property 3 (task 4.5), and how the password is stored is
 * Property 4 (task 4.6).
 *
 * Requirements: 1.1.
 */
final class OwnerRegistrationPropertyTest extends TestCase
{
    use TestTrait;

    private const CLOCK_START = '2025-04-17 08:15:30';

    /** Domain endings kept realistic, including a two-label public suffix. */
    private const TLDS = ['com', 'net', 'org', 'io', 'co.uk', 'example'];

    /** Whitespace the user may leave around a pasted address; `trim` removes all of it. */
    private const PADDING = ['', ' ', '  ', "\t"];

    private PDO $pdo;
    private FixedClock $clock;
    private UserRepository $users;
    private AuthService $auth;
    private DefaultPasswordPolicy $policy;

    protected function setUp(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_sqlite is not loaded.');
        }

        $this->policy = new DefaultPasswordPolicy();
    }

    // Feature: mental-health-diary, Property 2: Valid registration creates an Owner_Role account
    public function testValidRegistrationCreatesAnOwnerRoleAccount(): void
    {
        $this->limitTo(100)
            ->forAll(self::validEmail(), self::compliantPassword())
            ->then(function (string $email, string $password): void {
                // A fresh table per case, so "exactly one account" is a count of
                // the whole table rather than a difference of two counts.
                $this->startWithAnEmptyTable();

                // The generators are meant to produce inputs Requirement 1.1
                // admits; if they ever stop doing so, that fails here rather than
                // quietly turning this into a test about rejections.
                self::assertTrue(
                    EmailAddress::fromInput($email)->isValid(),
                    sprintf('the generated address %s should be valid', var_export($email, true))
                );
                self::assertTrue(
                    $this->policy->validate($password)->isOk(),
                    'the generated password should meet the policy'
                );

                $result = $this->auth->register($email, $password);

                self::assertTrue(
                    $result->isOk(),
                    sprintf(
                        'registering %s should succeed, got %s',
                        var_export($email, true),
                        var_export($result->message(), true)
                    )
                );

                $id = $result->value();
                self::assertInstanceOf(UserId::class, $id);

                $this->assertExactlyOneAccount();
                $this->assertStoredAsOwnerOfItsOwnData($id);
                $this->assertRetrievableByNormalisedEmail($email, $id);
            });
    }

    /**
     * Claim 1: one row, and one row only.
     */
    private function assertExactlyOneAccount(): void
    {
        $rows = SqliteUsersTable::snapshot($this->pdo);

        self::assertCount(1, $rows, 'a successful registration must create exactly one account');
    }

    /**
     * Claim 2, read straight out of the row: the Owner_Role, and a data owner
     * that is the account itself.
     */
    private function assertStoredAsOwnerOfItsOwnData(UserId $id): void
    {
        $row = $this->row($id);

        self::assertSame(UserRole::Owner->value, $row['role'], 'the new account must hold the Owner_Role');
        self::assertSame('owner', $row['role']);
        self::assertSame(
            $id->toString(),
            $row['data_owner_id'],
            'an owner account must be its own data owner'
        );

        // Active immediately: the Primary_User chose their own password, so there
        // is no invitation to accept.
        self::assertSame(UserStatus::Active->value, $row['status']);
        self::assertSame(0, (int) $row['failed_login_count']);
        self::assertNull($row['locked_until']);
        self::assertNull($row['deletion_requested_at']);

        // A password was stored, and it is not the password (Property 4 pins down
        // what kind of value it is).
        self::assertNotSame('', (string) $row['password_hash']);

        $timestamp = $this->clock->now()->format(UserRepository::DATETIME_FORMAT);
        self::assertSame($timestamp, $row['created_at']);
        self::assertSame($timestamp, $row['updated_at']);
    }

    /**
     * Claim 3: the normalised address finds it, and what comes back is the same
     * account the caller was told about.
     */
    private function assertRetrievableByNormalisedEmail(string $email, UserId $id): void
    {
        $normalized = EmailAddress::normalise($email);

        $found = $this->users->findByNormalizedEmail($normalized);

        self::assertInstanceOf(
            UserAccount::class,
            $found,
            sprintf('the account must be retrievable by the normalised address %s', var_export($normalized, true))
        );
        self::assertTrue($found->id->equals($id), 'the retrieved account must be the one just created');

        self::assertTrue($found->isOwner());
        self::assertSame(UserRole::Owner, $found->role);
        self::assertTrue($found->ownsItsOwnData());
        self::assertTrue($found->dataOwnerId->equals($found->id));
        self::assertSame(UserStatus::Active, $found->status);

        // Both stored forms: normalised for lookups, exactly as typed for display.
        self::assertSame($normalized, $found->emailNormalized);
        self::assertSame($email, $found->emailDisplay);

        // The uniqueness check and the id lookup see the same account.
        self::assertTrue($this->users->existsWithNormalizedEmail($normalized));
        self::assertInstanceOf(UserAccount::class, $this->users->findById($id));
        self::assertSame($normalized, $this->users->findById($id)?->emailNormalized);
    }

    private function startWithAnEmptyTable(): void
    {
        $this->pdo = SqliteUsersTable::connection();
        $this->clock = FixedClock::at(self::CLOCK_START);
        $this->users = new UserRepository($this->pdo);
        // bcrypt rather than the preferred Argon2id: a hundred registrations of
        // real work each, and this property is not about the algorithm.
        $this->auth = new AuthService(
            $this->users,
            $this->policy,
            $this->clock,
            new PasswordHasher(PASSWORD_BCRYPT)
        );

        self::assertSame([], SqliteUsersTable::snapshot($this->pdo));
    }

    /**
     * @return array<string, mixed>
     */
    private function row(UserId $id): array
    {
        $statement = $this->pdo->prepare('SELECT * FROM users WHERE id = :id');
        $statement->execute([':id' => $id->toString()]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        self::assertIsArray($row, 'the new account must be readable back from the database');

        return $row;
    }

    /**
     * Addresses Requirement 1.1 calls valid, varied where registration could go
     * wrong: mixed case, whitespace the user did not mean to paste, dotted and
     * tagged local parts, subdomains and multi-label suffixes.
     */
    private static function validEmail(): \Eris\Generator
    {
        return Generator\map(
            static function (array $parts): string {
                [$local, $tag, $subdomain, $domain, $tld, $before, $after] = $parts;

                $host = $subdomain === '' ? $domain : $subdomain . '.' . $domain;

                return $before . $local . $tag . '@' . $host . '.' . $tld . $after;
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
                Generator\elements(self::TLDS),
                Generator\elements(self::PADDING),
                Generator\elements(self::PADDING)
            )
        );
    }

    /**
     * One or two dot-separated tokens, so `roy.hillis@...` is covered without
     * ever producing the leading, trailing or doubled dots that no address may
     * contain.
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
     * A run of $minimum to $maximum characters from $alphabet, always starting
     * with a letter or digit.
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
     * Passwords the policy accepts: at least twelve characters carrying at least
     * one letter and one digit. The letter and the digit are placed at generated
     * positions rather than pinned to the ends, and the filler ranges over
     * symbols, spaces and non-ASCII letters so compliance is not accidentally
     * tied to a narrow alphabet.
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

                    // Two distinct positions: the letter sits somewhere strictly
                    // after the digit, wrapping around, so neither overwrites the
                    // other and the password always carries both classes.
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
