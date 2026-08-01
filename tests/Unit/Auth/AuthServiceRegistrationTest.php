<?php

declare(strict_types=1);

namespace Diary\Tests\Unit\Auth;

use Diary\Auth\AuthService;
use Diary\Auth\DefaultPasswordPolicy;
use Diary\Auth\PasswordHasher;
use Diary\Auth\PasswordPolicy;
use Diary\Auth\UserId;
use Diary\Auth\UserRepository;
use Diary\Auth\UserRole;
use Diary\Auth\UserStatus;
use Diary\Support\FixedClock;
use Diary\Support\Ulid;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Registration, Requirements 1.1 to 1.4.
 *
 * The service runs against a real in-memory database rather than a mocked
 * repository, because most of what these requirements promise is about what ends
 * up in - or stays out of - the `users` table.
 */
final class AuthServiceRegistrationTest extends TestCase
{
    private const PASSWORD = 'correct1horse2battery';

    private PDO $pdo;
    private UserRepository $users;
    private AuthService $auth;
    private FixedClock $clock;

    protected function setUp(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_sqlite is not loaded.');
        }

        $this->pdo = SqliteUsersTable::connection();
        $this->users = new UserRepository($this->pdo);
        $this->clock = FixedClock::at('2025-03-01 09:30:00');
        $this->auth = new AuthService(
            $this->users,
            new DefaultPasswordPolicy(),
            $this->clock,
            // The test-only hasher keeps the suite quick; the algorithm choice
            // itself is covered by PasswordHasherTest.
            PasswordHasher::forTests(),
        );
    }

    private function rowCount(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
    }

    public function testAValidRegistrationCreatesAnActiveOwnerThatOwnsItsOwnData(): void
    {
        $result = $this->auth->register('roy@example.com', self::PASSWORD);

        self::assertTrue($result->isOk(), (string) $result->message());

        $id = $result->value();
        self::assertInstanceOf(UserId::class, $id);
        self::assertTrue(Ulid::isValid($id->toString()));

        $account = $this->users->findById($id);
        self::assertNotNull($account);
        self::assertSame(UserRole::Owner, $account->role);
        self::assertSame(UserStatus::Active, $account->status);
        self::assertTrue($account->dataOwnerId->equals($account->id));
        self::assertSame('2025-03-01 09:30:00', $account->createdAt->format('Y-m-d H:i:s'));
        self::assertSame(1, $this->rowCount());
    }

    public function testTheNormalisedAddressIsStoredForLookupAndTheTypedOneForDisplay(): void
    {
        $typed = '  Roy.Hillis@Example.COM ';

        $result = $this->auth->register($typed, self::PASSWORD);

        self::assertTrue($result->isOk());

        $account = $this->users->findById($result->value());
        self::assertNotNull($account);
        self::assertSame('roy.hillis@example.com', $account->emailNormalized);
        self::assertSame($typed, $account->emailDisplay);
    }

    public function testThePasswordIsStoredOnlyAsAVerifiableSaltedHash(): void
    {
        $result = $this->auth->register('roy@example.com', self::PASSWORD);
        $account = $this->users->findById($result->value());

        self::assertNotNull($account);
        self::assertNotNull($account->passwordHash);
        self::assertNotSame(self::PASSWORD, $account->passwordHash);
        self::assertTrue(password_verify(self::PASSWORD, $account->passwordHash));

        // Nothing anywhere in the row resembles the plaintext.
        $row = SqliteUsersTable::snapshot($this->pdo)[0];
        foreach ($row as $value) {
            self::assertStringNotContainsString(self::PASSWORD, (string) $value);
        }
    }

    public function testTwoAccountsWithTheSamePasswordGetDifferentHashes(): void
    {
        $first = $this->auth->register('roy@example.com', self::PASSWORD);
        $second = $this->auth->register('mum@example.com', self::PASSWORD);

        $hashes = [
            $this->users->findById($first->value())?->passwordHash,
            $this->users->findById($second->value())?->passwordHash,
        ];

        self::assertNotNull($hashes[0]);
        self::assertNotSame($hashes[0], $hashes[1]);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function duplicateForms(): iterable
    {
        yield 'identical' => ['roy@example.com'];
        yield 'different case' => ['ROY@Example.COM'];
        yield 'surrounding whitespace' => ['  roy@example.com  '];
        yield 'both' => ["\tRoy@EXAMPLE.com\n"];
    }

    #[DataProvider('duplicateForms')]
    public function testASecondRegistrationForTheSameAddressIsRejectedAndTheAccountIsUntouched(
        string $duplicate
    ): void {
        $first = $this->auth->register('roy@example.com', self::PASSWORD);
        self::assertTrue($first->isOk());
        $before = SqliteUsersTable::snapshot($this->pdo);

        $this->clock->advanceMinutes(17);
        $result = $this->auth->register($duplicate, 'another9valid8password');

        self::assertTrue($result->isFailure());
        self::assertTrue($result->hasErrorCode(AuthService::EMAIL_TAKEN_ERROR_CODE));
        self::assertSame('That email address is already registered', $result->message());
        self::assertSame($result->message(), $result->fieldMessage(AuthService::EMAIL_FIELD));

        self::assertSame(1, $this->rowCount());
        self::assertSame($before, SqliteUsersTable::snapshot($this->pdo), 'the existing account must be untouched');
    }

    public function testAPasswordFailingThePolicyIsRejectedBeforeAnythingIsWritten(): void
    {
        $policy = new DefaultPasswordPolicy();

        $result = $this->auth->register('roy@example.com', 'short');

        self::assertTrue($result->isFailure());
        self::assertTrue($result->hasErrorCode(PasswordPolicy::ERROR_CODE));
        self::assertSame($policy->describe(), $result->message());
        self::assertSame($policy->describe(), $result->fieldMessage(PasswordPolicy::FIELD));
        self::assertSame(0, $this->rowCount(), 'a rejected registration stores no row and no hash');
    }

    public function testThePolicyIsAppliedBeforeTheUniquenessCheckSoTheRejectionNamesThePassword(): void
    {
        $this->auth->register('roy@example.com', self::PASSWORD);

        $result = $this->auth->register('ROY@example.com', 'weak');

        self::assertTrue($result->hasErrorCode(PasswordPolicy::ERROR_CODE));
        self::assertSame(1, $this->rowCount());
    }

    public function testAnAddressThatIsNotAnEmailAddressIsRejectedWithNoWrite(): void
    {
        foreach (['', '   ', 'not-an-email', 'roy@', '@example.com'] as $invalid) {
            $result = $this->auth->register($invalid, self::PASSWORD);

            self::assertTrue($result->isFailure(), sprintf('"%s" should be rejected', $invalid));
            self::assertTrue($result->hasErrorCode(AuthService::EMAIL_INVALID_ERROR_CODE));
            self::assertSame($result->message(), $result->fieldMessage(AuthService::EMAIL_FIELD));
        }

        self::assertSame(0, $this->rowCount());
    }

    public function testDifferentAddressesEachGetTheirOwnOwnerAccount(): void
    {
        $first = $this->auth->register('roy@example.com', self::PASSWORD);
        $second = $this->auth->register('roy+diary@example.com', self::PASSWORD);

        self::assertTrue($first->isOk());
        self::assertTrue($second->isOk());
        self::assertFalse($first->value()->equals($second->value()));
        self::assertSame(2, $this->rowCount());
    }
}
