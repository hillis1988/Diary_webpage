<?php

declare(strict_types=1);

namespace Diary\Tests\Property;

use Diary\Auth\AuthService;
use Diary\Auth\DefaultPasswordPolicy;
use Diary\Auth\EmailAddress;
use Diary\Auth\PasswordHasher;
use Diary\Auth\UserId;
use Diary\Auth\UserRepository;
use Diary\Support\FixedClock;
use Diary\Tests\Unit\Auth\SqliteUsersTable;
use Eris\Generator;
use Eris\TestTrait;
use LogicException;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Property 3: Email uniqueness is case- and whitespace-insensitive.
 *
 * Each run registers an address, then registers a variation of that same address
 * that differs only in letter case and surrounding whitespace, with a different
 * valid password. The second attempt has to be refused with the already-registered
 * message, and the account already there has to come out of it untouched.
 *
 * "Untouched" is asserted against the database rather than against the objects in
 * memory: the whole `users` table is snapshotted before the second attempt and
 * compared, byte for byte, with the snapshot afterwards. That is what catches the
 * two ways this could quietly go wrong - a second row for the same mailbox, or an
 * upsert rewriting the existing row's `password_hash` or `updated_at` with the
 * second attempt's values. The clock is advanced between the two registrations, so
 * a timestamp rewritten to "now" would have to differ from the stored one and the
 * snapshot comparison would see it.
 *
 * Requirements: 1.2.
 */
final class EmailUniquenessPropertyTest extends TestCase
{
    use TestTrait;

    /** Whitespace trim() removes, in the combinations an address might arrive with. */
    private const PADDING = ['', ' ', '  ', '   ', "\t", "\n", "\r\n", " \t", "\x0B "];

    /** Characters an address core is built from; all lowercase, so case flips are reversible. */
    private const CORE_CHARACTERS = [
        'a', 'b', 'c', 'm', 'r', 'y', 'z',
        '0', '1', '7', '9',
    ];

    private const TOP_LEVEL_DOMAINS = ['com', 'co.uk', 'org', 'net', 'dev'];

    /**
     * Verifying a bcrypt hash costs as much as producing one, and the snapshot
     * comparison already proves the stored hash is unchanged byte for byte. A
     * handful of runs additionally confirm the surviving hash still belongs to
     * the first password and not the second.
     */
    private const CREDENTIAL_CHECKS = 5;

    private int $caseVariations = 0;
    private int $paddedVariations = 0;
    private int $credentialChecks = 0;

    protected function setUp(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_sqlite is not loaded.');
        }

        $this->caseVariations = 0;
        $this->paddedVariations = 0;
        $this->credentialChecks = 0;
    }

    // Feature: mental-health-diary, Property 3: Email uniqueness is case- and whitespace-insensitive
    public function testEmailUniquenessIsCaseAndWhitespaceInsensitive(): void
    {
        $this->limitTo(100)
            ->forAll(self::scenario())
            ->then(function (array $scenario): void {
                [
                    'base' => $base,
                    'variation' => $variation,
                    'firstPassword' => $firstPassword,
                    'secondPassword' => $secondPassword,
                    'advanceSeconds' => $advanceSeconds,
                ] = $scenario;

                // The generator's own claim: the two strings are the same address
                // written differently, nothing more.
                self::assertSame(
                    EmailAddress::normalise($base),
                    EmailAddress::normalise($variation),
                    'the generated variation must differ only in case and surrounding whitespace: '
                    . self::describe($scenario)
                );
                self::assertTrue(
                    EmailAddress::fromInput($base)->isValid(),
                    'the generated address must be one the application accepts: ' . self::describe($scenario)
                );
                self::assertNotSame($firstPassword, $secondPassword);

                $clock = FixedClock::at('2024-05-01 08:15:00');
                $pdo = SqliteUsersTable::connection();
                $service = new AuthService(
                    new UserRepository($pdo),
                    new DefaultPasswordPolicy(),
                    $clock,
                    // bcrypt regardless of what this PHP build prefers: Argon2id's
                    // memory cost would dominate a hundred-run property test.
                    new PasswordHasher(PASSWORD_BCRYPT),
                );

                $first = $service->register($base, $firstPassword);

                self::assertTrue($first->isOk(), 'the first registration must succeed: ' . self::describe($scenario));
                self::assertInstanceOf(UserId::class, $first->value());
                $registeredAt = UserRepository::formatDateTime($clock->now());

                $before = SqliteUsersTable::snapshot($pdo);
                self::assertCount(1, $before, 'one registration must produce exactly one row');

                // Move time on, so anything rewriting a timestamp to "now" would
                // write a value different from the one stored.
                $clock->advanceSeconds($advanceSeconds);
                self::assertNotSame(
                    $registeredAt,
                    UserRepository::formatDateTime($clock->now()),
                    'the clock must have moved far enough for a rewritten timestamp to be visible'
                );

                $second = $service->register($variation, $secondPassword);

                self::assertTrue(
                    $second->isFailure(),
                    'the variation must not create a second account: ' . self::describe($scenario)
                );
                self::assertTrue(
                    $second->hasErrorCode(AuthService::EMAIL_TAKEN_ERROR_CODE),
                    'the refusal must be the already-registered one, not some other failure: '
                    . self::describe($scenario)
                );
                self::assertSame(AuthService::EMAIL_TAKEN_MESSAGE, $second->message());
                self::assertSame(
                    AuthService::EMAIL_TAKEN_MESSAGE,
                    $second->fieldMessage(AuthService::EMAIL_FIELD)
                );

                try {
                    $second->value();
                    self::fail('a refused registration must not yield an account id');
                } catch (LogicException) {
                    // Expected.
                }

                $after = SqliteUsersTable::snapshot($pdo);

                self::assertSame(
                    $before,
                    $after,
                    'the existing account must be untouched, column for column: ' . self::describe($scenario)
                );
                self::assertSame(
                    1,
                    (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn(),
                    'exactly one account must remain: ' . self::describe($scenario)
                );

                $row = $after[0];

                self::assertSame($first->value()->toString(), $row['id']);
                self::assertSame(EmailAddress::normalise($base), $row['email_normalized']);
                self::assertSame($base, $row['email_display'], 'the address is stored as first typed');
                self::assertSame($registeredAt, $row['created_at']);
                self::assertSame($registeredAt, $row['updated_at']);

                if ($this->credentialChecks < self::CREDENTIAL_CHECKS) {
                    ++$this->credentialChecks;

                    $hasher = new PasswordHasher(PASSWORD_BCRYPT);
                    $storedHash = (string) $row['password_hash'];

                    self::assertTrue(
                        $hasher->verify($firstPassword, $storedHash),
                        'the stored hash must still be the first password\'s'
                    );
                    self::assertFalse(
                        $hasher->verify($secondPassword, $storedHash),
                        'the refused attempt\'s password must not have replaced the stored hash'
                    );
                }

                if (trim($base) !== trim($variation)) {
                    ++$this->caseVariations;
                }

                if ($variation !== trim($variation)) {
                    ++$this->paddedVariations;
                }
            });

        // The run only says something about case and whitespace if it produced both.
        self::assertGreaterThan(0, $this->caseVariations, 'no case-only variation was generated');
        self::assertGreaterThan(0, $this->paddedVariations, 'no whitespace-padded variation was generated');
    }

    /**
     * A base address, a variation of it differing only in case and surrounding
     * whitespace, two different policy-passing passwords, and how far to move the
     * clock between the two registrations.
     *
     * @return \Eris\Generator<array{base: string, variation: string, firstPassword: string,
     *                              secondPassword: string, advanceSeconds: int}>
     */
    private static function scenario(): \Eris\Generator
    {
        return Generator\bind(
            self::addressCore(),
            static fn (string $core): \Eris\Generator => Generator\map(
                static fn (array $parts): array => [
                    'base' => $parts[0],
                    'variation' => $parts[1],
                    'firstPassword' => $parts[2],
                    'secondPassword' => $parts[3],
                    'advanceSeconds' => $parts[4],
                ],
                Generator\tuple(
                    self::casedAndPadded($core),
                    self::casedAndPadded($core),
                    self::password('a1'),
                    self::password('b2'),
                    Generator\choose(1, 86_400)
                )
            )
        );
    }

    /**
     * A valid, lowercase address: a local part that may carry one of the separators
     * real addresses use, a domain label, and a top-level domain.
     */
    private static function addressCore(): \Eris\Generator
    {
        return Generator\map(
            static function (array $parts): string {
                [$local, $separator, $localTail, $domain, $tld] = $parts;

                $localPart = implode('', $local);

                if ($separator !== '') {
                    $localPart .= $separator . implode('', $localTail);
                }

                return $localPart . '@' . implode('', $domain) . '.' . $tld;
            },
            Generator\tuple(
                self::characters(1, 8),
                Generator\elements(['', '.', '+', '-', '_']),
                self::characters(1, 4),
                self::characters(1, 8),
                Generator\elements(self::TOP_LEVEL_DOMAINS)
            )
        );
    }

    /**
     * The same address with a random subset of its letters uppercased and
     * whitespace on either end - the two things Requirement 1.2 says make no
     * difference. Digits and separators are unaffected by the case flip, and
     * because the core is ASCII, lowercasing the result gives the core back.
     */
    private static function casedAndPadded(string $core): \Eris\Generator
    {
        return Generator\map(
            static function (array $parts) use ($core): string {
                [$flags, $left, $right] = $parts;

                $cased = '';

                foreach (str_split($core) as $position => $character) {
                    $cased .= ($flags[$position] ?? false) ? strtoupper($character) : $character;
                }

                return $left . $cased . $right;
            },
            Generator\tuple(
                Generator\vector(strlen($core), Generator\elements([true, false])),
                Generator\elements(self::PADDING),
                Generator\elements(self::PADDING)
            )
        );
    }

    /**
     * A password the policy accepts: at least twelve characters with a letter and a
     * digit. The suffix both supplies the two required classes and keeps the two
     * passwords in a scenario distinct.
     */
    private static function password(string $suffix): \Eris\Generator
    {
        return Generator\map(
            static fn (array $characters): string => implode('', $characters) . $suffix,
            Generator\vector(14, Generator\elements(array_merge(self::CORE_CHARACTERS, [' ', '-', '!', '.'])))
        );
    }

    /**
     * @param array<string, mixed> $scenario
     */
    private static function describe(array $scenario): string
    {
        return sprintf(
            'base %s, variation %s',
            self::quote((string) $scenario['base']),
            self::quote((string) $scenario['variation'])
        );
    }

    /**
     * Whitespace differences are the point here, so the hex goes alongside the text.
     */
    private static function quote(string $value): string
    {
        return sprintf('"%s" (hex %s)', $value, bin2hex($value));
    }

    /**
     * A vector of between $minimum and $maximum address characters.
     */
    private static function characters(int $minimum, int $maximum): \Eris\Generator
    {
        return Generator\bind(
            Generator\choose($minimum, $maximum),
            static fn (int $length): \Eris\Generator => Generator\vector(
                $length,
                Generator\elements(self::CORE_CHARACTERS)
            )
        );
    }
}
