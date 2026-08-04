<?php

declare(strict_types=1);

namespace Diary\Tests\Property;

use Diary\Auth\AuthService;
use Diary\Auth\DefaultPasswordPolicy;
use Diary\Auth\PasswordHasher;
use Diary\Auth\PasswordPolicy;
use Diary\Auth\UserId;
use Diary\Auth\UserRepository;
use Diary\Support\FixedClock;
use Diary\Tests\Unit\Auth\SqliteUsersTable;
use Eris\Generator;
use Eris\TestTrait;
use PHPUnit\Framework\TestCase;

/**
 * Property 4: Passwords are stored only as salted one-way hashes.
 *
 * For any policy-compliant password, the stored credential verifies against that
 * password, is not equal to it, does not contain it as a substring, and differs
 * between two accounts that chose the same password; and for any rejected
 * registration, no credential is stored at all.
 *
 * Each iteration registers two accounts, with different email addresses, on the
 * same generated password. That pairing is what makes the salt observable: a
 * plain digest of the password would give both accounts the same stored value,
 * so two different hashes for one password can only come from per-account salt.
 * The reverse direction - that the stored value really is that password's hash
 * and not something unrelated - is covered by verifying against it.
 *
 * The "not equal, not a substring" checks are widened from the `password_hash`
 * column to a full table snapshot, so a plaintext copy smuggled into any other
 * column (a display email, an audit-ish field, anything) is caught too.
 *
 * The rejected half then submits a policy-violating password to the same store
 * and asserts the snapshot is byte-for-byte unchanged: no third row and nothing
 * altered on the rows already there, so no credential was stored for the
 * refused password. Time comes from a FixedClock and the
 * whole property runs against an in-memory database, so nothing sleeps and
 * nothing reaches the network.
 *
 * The hasher is {@see PasswordHasher::forTests()} - bcrypt at its minimum cost -
 * so a hundred iterations of two registrations each stay cheap. That costs this
 * property nothing it claims: a low-cost bcrypt hash is still salted per call and
 * still one-way, which is exactly what the two-accounts-one-password comparison
 * and the `password_get_info()` check below observe. Only the work factor is
 * lowered, and *which* algorithm production picks is pinned by
 * {@see \Diary\Tests\Unit\Auth\PasswordHasherTest} against the real defaults.
 *
 * Requirements: 1.4.
 */
final class PasswordHashStoragePropertyTest extends TestCase
{
    use TestTrait;

    private const LETTERS = ['a', 'k', 'z', 'B', 'R', 'é', 'Ж'];
    private const DIGITS = ['0', '3', '7', '9'];
    private const NEUTRAL = [' ', '!', '-', '_', '.', '~', '€'];

    private DefaultPasswordPolicy $policy;

    protected function setUp(): void
    {
        $this->policy = new DefaultPasswordPolicy();
    }

    // Feature: mental-health-diary, Property 4: Passwords are stored only as salted one-way hashes
    public function testPasswordsAreStoredOnlyAsSaltedOneWayHashes(): void
    {
        $this->limitTo(100)
            ->forAll(
                self::compliantPassword(),
                self::rejectedPassword(),
                self::emailLocalPart()
            )
            ->then(function (string $password, string $rejected, string $local): void {
                // Guards on the generators, so a failure below is about storage
                // rather than about having generated the wrong kind of input.
                self::assertTrue(
                    $this->policy->validate($password)->isOk(),
                    'the generator must produce a policy-compliant password'
                );
                self::assertTrue(
                    $this->policy->validate($rejected)->isFailure(),
                    'the generator must produce a password the policy rejects'
                );

                $pdo = SqliteUsersTable::connection();
                $users = new UserRepository($pdo);
                $hasher = PasswordHasher::forTests();
                $auth = new AuthService(
                    $users,
                    $this->policy,
                    FixedClock::at('2025-03-09 08:15:00'),
                    $hasher,
                );

                // Two accounts, one password.
                $hashes = [];

                foreach (['first', 'second'] as $index => $prefix) {
                    $result = $auth->register(sprintf('%s.%s@example.com', $prefix, $local), $password);

                    self::assertTrue(
                        $result->isOk(),
                        sprintf('registration %d was expected to succeed', $index + 1)
                    );

                    /** @var UserId $id */
                    $id = $result->value();
                    $account = $users->findById($id);

                    self::assertNotNull($account, 'the registered account must be readable back');

                    $stored = $account->passwordHash;

                    self::assertNotNull($stored, 'a successful registration must store a credential');
                    self::assertNotSame(
                        $password,
                        $stored,
                        'the stored credential must not be the password itself'
                    );
                    self::assertStringNotContainsString(
                        $password,
                        $stored,
                        'the stored credential must not carry the password inside it'
                    );
                    self::assertTrue(
                        $hasher->verify($password, $stored),
                        'the stored credential must verify against the password it was made from'
                    );
                    self::assertNotSame(
                        'unknown',
                        (string) (password_get_info($stored)['algoName'] ?? 'unknown'),
                        'the stored credential must be a recognised one-way hash, not an ad hoc encoding'
                    );

                    $hashes[] = $stored;
                }

                // Per-account salt: one password, two accounts, two credentials.
                self::assertNotSame(
                    $hashes[0],
                    $hashes[1],
                    'two accounts sharing a password must not share a stored credential'
                );

                $snapshot = SqliteUsersTable::snapshot($pdo);

                self::assertCount(2, $snapshot);
                self::assertPlaintextAbsent($password, $snapshot);

                // The rejected half: nothing at all is stored for a password the
                // policy refuses, and the accounts already there are untouched.
                $rejection = $auth->register(sprintf('third.%s@example.com', $local), $rejected);

                self::assertTrue($rejection->isFailure(), 'a policy-violating password must be rejected');
                self::assertTrue($rejection->hasErrorCode(PasswordPolicy::ERROR_CODE));

                // Equality of the whole snapshot is the strongest form of "no
                // credential is stored at all": no third row, and not a byte
                // changed on the two rows that were already there. A substring
                // search for the rejected password would be weaker and, for the
                // short candidates, would collide with hash text by chance.
                self::assertSame(
                    $snapshot,
                    SqliteUsersTable::snapshot($pdo),
                    'a rejected registration must leave the table exactly as it was'
                );
            });
    }

    /**
     * No column of any row holds the plaintext, in either the form submitted or
     * the lowercased form a normalising column would hold.
     *
     * @param list<array<string, mixed>> $snapshot
     */
    private static function assertPlaintextAbsent(string $plaintext, array $snapshot): void
    {
        if ($plaintext === '') {
            return;
        }

        $lowered = mb_check_encoding($plaintext, 'UTF-8')
            ? mb_strtolower($plaintext, 'UTF-8')
            : strtolower($plaintext);

        foreach ($snapshot as $row) {
            foreach ($row as $column => $value) {
                if (!is_scalar($value)) {
                    continue;
                }

                $text = (string) $value;

                self::assertStringNotContainsString(
                    $plaintext,
                    $text,
                    sprintf('column %s holds the plaintext password', (string) $column)
                );

                $loweredText = mb_check_encoding($text, 'UTF-8')
                    ? mb_strtolower($text, 'UTF-8')
                    : strtolower($text);

                self::assertStringNotContainsString(
                    $lowered,
                    $loweredText,
                    sprintf('column %s holds the plaintext password in normalised form', (string) $column)
                );
            }
        }
    }

    /**
     * Passwords the policy accepts: at least twelve characters with a letter and
     * a digit, mixing ASCII, accented and non-Latin letters, spaces and symbols.
     * Kept short of bcrypt's 72-byte input limit and free of null bytes so the
     * property is about storage rather than about an algorithm's own edge cases.
     */
    private static function compliantPassword(): \Eris\Generator
    {
        return Generator\map(
            static fn (array $characters): string => implode('', $characters) . 'k4',
            Generator\bind(
                Generator\choose(10, 18),
                static fn (int $length): \Eris\Generator => Generator\vector(
                    $length,
                    Generator\elements(array_merge(self::LETTERS, self::DIGITS, self::NEUTRAL))
                )
            )
        );
    }

    /**
     * Passwords the policy refuses, one per way of failing: too short, long but
     * with no digit, long but with no letter, long but with neither.
     */
    private static function rejectedPassword(): \Eris\Generator
    {
        return Generator\oneOf(
            self::stringOf(0, 11, array_merge(self::LETTERS, self::DIGITS)),
            self::stringOf(12, 20, self::LETTERS),
            self::stringOf(12, 20, self::DIGITS),
            self::stringOf(12, 20, self::NEUTRAL)
        );
    }

    /**
     * @param list<string> $alphabet
     */
    private static function stringOf(int $minimum, int $maximum, array $alphabet): \Eris\Generator
    {
        return Generator\bind(
            Generator\choose($minimum, $maximum),
            static fn (int $length): \Eris\Generator => Generator\map(
                static fn (array $characters): string => implode('', $characters),
                Generator\vector($length, Generator\elements($alphabet))
            )
        );
    }

    /**
     * The distinguishing part of the three addresses used in one iteration.
     */
    private static function emailLocalPart(): \Eris\Generator
    {
        return Generator\map(
            static fn (array $characters): string => implode('', $characters),
            Generator\vector(6, Generator\elements(['a', 'b', 'c', 'd', 'e', 'f', '1', '2', '3']))
        );
    }
}
