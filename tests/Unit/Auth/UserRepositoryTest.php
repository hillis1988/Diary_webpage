<?php

declare(strict_types=1);

namespace Diary\Tests\Unit\Auth;

use Diary\Auth\DuplicateEmailException;
use Diary\Auth\EmailAddress;
use Diary\Auth\UserAccount;
use Diary\Auth\UserId;
use Diary\Auth\UserRepository;
use Diary\Auth\UserRole;
use Diary\Auth\UserStatus;
use Diary\Support\FixedClock;
use Diary\Support\Ulid;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * The repository is where Requirement 1.2's uniqueness actually becomes true, so
 * what is tested here is the round trip, the lookup by normalised address, and
 * what a second insert for the same address does to the row already stored.
 */
final class UserRepositoryTest extends TestCase
{
    private PDO $pdo;
    private UserRepository $users;
    private FixedClock $clock;

    protected function setUp(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_sqlite is not loaded.');
        }

        $this->pdo = SqliteUsersTable::connection();
        $this->users = new UserRepository($this->pdo);
        $this->clock = FixedClock::at('2025-03-01 09:30:00');
    }

    private function owner(string $email = 'roy@example.com'): UserAccount
    {
        return UserAccount::newOwner(
            UserId::fromString(Ulid::generate($this->clock)),
            EmailAddress::fromInput($email),
            '$2y$11$abcdefghijklmnopqrstuvwxyz0123456789ABCDEFGHIJKLMNOPQR',
            $this->clock->now(),
        );
    }

    public function testAnInsertedOwnerComesBackWithEveryFieldIntact(): void
    {
        $account = $this->owner(' Roy@Example.COM ');
        $this->users->insert($account);

        $stored = $this->users->findByNormalizedEmail('roy@example.com');

        self::assertNotNull($stored);
        self::assertTrue($stored->id->equals($account->id));
        self::assertSame('roy@example.com', $stored->emailNormalized);
        self::assertSame(' Roy@Example.COM ', $stored->emailDisplay);
        self::assertSame($account->passwordHash, $stored->passwordHash);
        self::assertSame(UserRole::Owner, $stored->role);
        self::assertSame(UserStatus::Active, $stored->status);
        self::assertTrue($stored->ownsItsOwnData());
        self::assertSame(0, $stored->failedLoginCount);
        self::assertNull($stored->lockedUntil);
        self::assertNull($stored->deletionRequestedAt);
        self::assertSame('2025-03-01 09:30:00', $stored->createdAt->format('Y-m-d H:i:s'));
        self::assertSame('UTC', $stored->createdAt->getTimezone()->getName());
        self::assertSame($stored->createdAt->format('c'), $stored->updatedAt->format('c'));
    }

    public function testTheSelfReferentialDataOwnerIsAcceptedWithForeignKeysEnforced(): void
    {
        self::assertSame('1', (string) $this->pdo->query('PRAGMA foreign_keys')->fetchColumn());

        $account = $this->owner();
        $this->users->insert($account);

        self::assertNotNull($this->users->findById($account->id));
    }

    public function testLookupByEmailNormalisesWhateverItIsGiven(): void
    {
        $this->users->insert($this->owner('roy@example.com'));

        self::assertNotNull($this->users->findByEmail('  ROY@Example.com '));
        self::assertNull($this->users->findByEmail('someone.else@example.com'));
    }

    public function testExistenceIsCheckedOnTheNormalisedAddress(): void
    {
        $this->users->insert($this->owner('roy@example.com'));

        self::assertTrue($this->users->existsWithNormalizedEmail('roy@example.com'));
        self::assertFalse($this->users->existsWithNormalizedEmail('ROY@example.com'));
        self::assertFalse($this->users->existsWithNormalizedEmail('other@example.com'));
    }

    public function testASecondInsertForTheSameAddressIsRefusedAndChangesNothing(): void
    {
        $first = $this->owner('roy@example.com');
        $this->users->insert($first);
        $before = SqliteUsersTable::snapshot($this->pdo);

        $this->clock->advanceMinutes(10);
        $second = UserAccount::newOwner(
            UserId::fromString(Ulid::generate($this->clock)),
            EmailAddress::fromInput('ROY@example.com'),
            '$2y$11$zzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzz',
            $this->clock->now(),
        );

        try {
            $this->users->insert($second);
            self::fail('Expected a DuplicateEmailException.');
        } catch (DuplicateEmailException) {
            // expected
        }

        self::assertSame($before, SqliteUsersTable::snapshot($this->pdo));
        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM users')->fetchColumn());
        self::assertNull($this->users->findById($second->id));
    }

    public function testAnUnknownAccountIsAbsentRatherThanAnError(): void
    {
        self::assertNull($this->users->findById(Ulid::generate($this->clock)));
        self::assertNull($this->users->findByNormalizedEmail('nobody@example.com'));
    }
}
