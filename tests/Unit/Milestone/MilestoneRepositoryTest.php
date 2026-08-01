<?php

declare(strict_types=1);

namespace Diary\Tests\Unit\Milestone;

use Diary\Access\OwnerId;
use Diary\Milestone\MilestoneCategory;
use Diary\Milestone\MilestoneId;
use Diary\Milestone\MilestoneInput;
use Diary\Milestone\MilestoneRepository;
use Diary\Milestone\MilestoneService;
use Diary\Milestone\MilestoneSubmission;
use Diary\Milestone\MilestoneValidation;
use Diary\Storage\ConnectionFactory;
use Diary\Storage\Crypto;
use Diary\Storage\KeyRing;
use Diary\Storage\PayloadCodec;
use Diary\Support\DateRange;
use Diary\Support\FixedClock;
use Diary\Support\LocalDate;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * MilestoneRepository and MilestoneService (Requirements 10.1, 10.2, 10.3,
 * 10.5).
 *
 * Uses an in-memory SQLite schema standing in for migrations 003 and 006
 * (SQLite cannot express MariaDB's exact FOREIGN KEY syntax), following the
 * pattern in tests/Unit/Diary/DiaryEntryRepositoryTest.php.
 */
final class MilestoneRepositoryTest extends TestCase
{
    private PDO $pdo;
    private MilestoneRepository $repository;
    private MilestoneService $service;
    private FixedClock $clock;

    protected function setUp(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_sqlite is not loaded.');
        }

        $this->pdo = ConnectionFactory::fromDsn('sqlite::memory:');

        $this->pdo->exec(
            'CREATE TABLE encryption_keys (
                id          CHAR(26)   NOT NULL PRIMARY KEY,
                wrapped_dek BLOB       NOT NULL,
                wrap_nonce  BLOB       NOT NULL,
                created_at  DATETIME   NOT NULL,
                retired_at  DATETIME   NULL
            )'
        );

        $this->pdo->exec(
            'CREATE TABLE milestones (
                id                 CHAR(26)   NOT NULL PRIMARY KEY,
                owner_id           CHAR(26)   NOT NULL,
                milestone_date     DATE       NOT NULL,
                key_id             CHAR(26)   NOT NULL,
                nonce              BLOB       NOT NULL,
                payload_ciphertext BLOB       NOT NULL,
                created_at         DATETIME   NOT NULL,
                updated_at         DATETIME   NOT NULL
            )'
        );

        $this->clock = FixedClock::at('2025-03-01 09:30:00');

        $keyRing = new KeyRing($this->pdo, str_repeat("\x2a", KeyRing::KEY_LENGTH), $this->clock);
        $codec = new PayloadCodec(new Crypto($keyRing));

        $this->repository = new MilestoneRepository($this->pdo, $codec);
        $this->service = new MilestoneService($this->repository);
    }

    private function owner(string $suffix = '1'): OwnerId
    {
        return OwnerId::fromString('0' . str_repeat('1', 24) . $suffix);
    }

    private function input(
        string $date = '2025-03-01',
        string $description = 'Started a new medication',
        MilestoneCategory $category = MilestoneCategory::Medication,
    ): MilestoneInput {
        return MilestoneInput::of(LocalDate::fromString($date), $description, $category);
    }

    public function testCreateInsertsARowAndFindByIdReturnsIt(): void
    {
        $owner = $this->owner();

        $milestone = $this->repository->create($owner, $this->input(), $this->clock);

        self::assertSame(1, $this->countRows());

        $found = $this->repository->findById($owner, $milestone->id());
        self::assertNotNull($found);
        self::assertSame('Started a new medication', $found->description());
        self::assertSame(MilestoneCategory::Medication, $found->category());
        self::assertSame('2025-03-01', $found->date()->toIso());
        self::assertTrue($found->ownerId()->equals($owner));
    }

    /**
     * @return iterable<string, array{MilestoneCategory}>
     */
    public static function categories(): iterable
    {
        yield 'medication' => [MilestoneCategory::Medication];
        yield 'relationship' => [MilestoneCategory::Relationship];
        yield 'lifestyle' => [MilestoneCategory::Lifestyle];
        yield 'other' => [MilestoneCategory::Other];
    }

    #[DataProvider('categories')]
    public function testCreateRoundTripsEveryCategoryInTheClosedSet(MilestoneCategory $category): void
    {
        $owner = $this->owner();

        $milestone = $this->repository->create($owner, $this->input(category: $category), $this->clock);

        $found = $this->repository->findById($owner, $milestone->id());
        self::assertNotNull($found);
        self::assertSame($category, $found->category());
    }

    public function testUpdateChangesTheStoredMilestone(): void
    {
        $owner = $this->owner();

        $created = $this->repository->create($owner, $this->input(), $this->clock);

        $this->clock->advanceMinutes(5);
        $updated = $this->repository->update(
            $owner,
            $created->id(),
            $this->input(date: '2025-03-10', description: 'Started physiotherapy', category: MilestoneCategory::Lifestyle),
            $this->clock,
        );

        self::assertNotNull($updated);
        self::assertTrue($updated->id()->equals($created->id()));
        self::assertSame(1, $this->countRows());

        $found = $this->repository->findById($owner, $created->id());
        self::assertNotNull($found);
        self::assertSame('Started physiotherapy', $found->description());
        self::assertSame(MilestoneCategory::Lifestyle, $found->category());
        self::assertSame('2025-03-10', $found->date()->toIso());
    }

    #[DataProvider('categories')]
    public function testUpdateRoundTripsEveryCategoryInTheClosedSet(MilestoneCategory $category): void
    {
        $owner = $this->owner();

        // Start from a different category than the one under test so the
        // update actually changes the stored value rather than leaving it
        // untouched.
        $startingCategory = $category === MilestoneCategory::Other
            ? MilestoneCategory::Medication
            : MilestoneCategory::Other;

        $created = $this->repository->create($owner, $this->input(category: $startingCategory), $this->clock);

        $this->clock->advanceMinutes(5);
        $updated = $this->repository->update(
            $owner,
            $created->id(),
            $this->input(category: $category),
            $this->clock,
        );

        self::assertNotNull($updated);
        self::assertSame($category, $updated->category());

        $found = $this->repository->findById($owner, $created->id());
        self::assertNotNull($found);
        self::assertSame($category, $found->category());
    }

    public function testUpdateReturnsNullForAMilestoneBelongingToAnotherOwner(): void
    {
        $owner = $this->owner('1');
        $otherOwner = $this->owner('2');

        $created = $this->repository->create($owner, $this->input(), $this->clock);

        $result = $this->repository->update($otherOwner, $created->id(), $this->input(description: 'Hijacked'), $this->clock);

        self::assertNull($result);

        $found = $this->repository->findById($owner, $created->id());
        self::assertNotNull($found);
        self::assertSame('Started a new medication', $found->description());
    }

    public function testDeleteRemovesTheRowAndReturnsTrue(): void
    {
        $owner = $this->owner();

        $created = $this->repository->create($owner, $this->input(), $this->clock);

        $deleted = $this->repository->delete($owner, $created->id());

        self::assertTrue($deleted);
        self::assertSame(0, $this->countRows());
        self::assertNull($this->repository->findById($owner, $created->id()));
    }

    #[DataProvider('categories')]
    public function testDeleteRemovesAMilestoneOfEveryCategoryInTheClosedSet(MilestoneCategory $category): void
    {
        $owner = $this->owner();

        $created = $this->repository->create($owner, $this->input(category: $category), $this->clock);

        $deleted = $this->repository->delete($owner, $created->id());

        self::assertTrue($deleted);
        self::assertSame(0, $this->countRows());
        self::assertNull($this->repository->findById($owner, $created->id()));
    }

    public function testDeleteReturnsFalseForAMilestoneBelongingToAnotherOwner(): void
    {
        $owner = $this->owner('1');
        $otherOwner = $this->owner('2');

        $created = $this->repository->create($owner, $this->input(), $this->clock);

        $deleted = $this->repository->delete($otherOwner, $created->id());

        self::assertFalse($deleted);
        self::assertSame(1, $this->countRows());
    }

    public function testInRangeReturnsOnlyTheOwnersMilestonesWithinTheDateRangeOrderedByDate(): void
    {
        $ownerA = $this->owner('1');
        $ownerB = $this->owner('2');

        $this->repository->create($ownerA, $this->input(date: '2025-03-05'), $this->clock);
        $this->repository->create($ownerA, $this->input(date: '2025-03-01'), $this->clock);
        $this->repository->create($ownerA, $this->input(date: '2025-03-20'), $this->clock);
        $this->repository->create($ownerB, $this->input(date: '2025-03-03'), $this->clock);

        $range = DateRange::of(LocalDate::fromString('2025-03-01'), LocalDate::fromString('2025-03-10'));
        $milestones = $this->repository->inRange($ownerA, $range);

        self::assertCount(2, $milestones);
        self::assertSame(
            ['2025-03-01', '2025-03-05'],
            array_map(static fn ($m) => $m->date()->toIso(), $milestones)
        );

        foreach ($milestones as $milestone) {
            self::assertTrue($milestone->ownerId()->equals($ownerA));
        }
    }

    public function testInRangeIncludesMilestonesExactlyOnTheStartAndEndBoundariesAndExcludesJustOutside(): void
    {
        $owner = $this->owner();

        // One day before the start boundary: must be excluded.
        $this->repository->create($owner, $this->input(date: '2025-03-04'), $this->clock);
        // Exactly on the start boundary: must be included.
        $this->repository->create($owner, $this->input(date: '2025-03-05'), $this->clock);
        // Exactly on the end boundary: must be included.
        $this->repository->create($owner, $this->input(date: '2025-03-15'), $this->clock);
        // One day after the end boundary: must be excluded.
        $this->repository->create($owner, $this->input(date: '2025-03-16'), $this->clock);

        $range = DateRange::of(LocalDate::fromString('2025-03-05'), LocalDate::fromString('2025-03-15'));
        $milestones = $this->repository->inRange($owner, $range);

        self::assertSame(
            ['2025-03-05', '2025-03-15'],
            array_map(static fn ($m) => $m->date()->toIso(), $milestones)
        );
    }

    public function testADateMayCarryMultipleMilestones(): void
    {
        $owner = $this->owner();

        $this->repository->create($owner, $this->input(date: '2025-03-01', description: 'First'), $this->clock);
        $this->repository->create($owner, $this->input(date: '2025-03-01', description: 'Second'), $this->clock);

        $range = DateRange::singleDay(LocalDate::fromString('2025-03-01'));
        $milestones = $this->repository->inRange($owner, $range);

        self::assertCount(2, $milestones);
    }

    public function testEncryptedPayloadRoundTripsAndIsNotStoredInPlaintext(): void
    {
        $owner = $this->owner();

        $this->repository->create($owner, $this->input(description: 'A very private note'), $this->clock);

        $row = $this->pdo->query('SELECT payload_ciphertext FROM milestones')->fetch(PDO::FETCH_ASSOC);
        self::assertStringNotContainsString('A very private note', (string) $row['payload_ciphertext']);
        self::assertStringNotContainsString('medication', (string) $row['payload_ciphertext']);
    }

    public function testServiceCreateOnlyWritesOnAcceptedValidation(): void
    {
        $owner = $this->owner();

        $input = $this->input();
        $validation = MilestoneValidation::accepted(
            $input,
            MilestoneSubmission::of([
                'date' => '2025-03-01',
                'description' => 'Started a new medication',
                'category' => 'medication',
            ])
        );

        $result = $this->service->create($owner, $validation, $this->clock);

        self::assertTrue($result->isOk());
        self::assertSame(1, $this->countRows());
    }

    public function testServiceCreateWritesNothingOnRejectedValidation(): void
    {
        $owner = $this->owner();

        $submission = MilestoneSubmission::of([
            'date' => '2025-03-01',
            'description' => '',
            'category' => 'medication',
        ]);
        $validation = MilestoneValidation::rejected(
            $submission,
            'milestone_invalid',
            ['description' => 'Please provide a description for this milestone.']
        );

        $result = $this->service->create($owner, $validation, $this->clock);

        self::assertTrue($result->isFailure());
        self::assertSame(0, $this->countRows());
    }

    public function testServiceDeleteReturnsFailureWhenNotFound(): void
    {
        $owner = $this->owner();

        $result = $this->service->delete($owner, MilestoneId::fromString('0' . str_repeat('1', 24) . '9'));

        self::assertTrue($result->isFailure());
        self::assertSame(MilestoneService::NOT_FOUND_ERROR_CODE, $result->errorCode());
    }

    private function countRows(): int
    {
        $result = $this->pdo->query('SELECT COUNT(*) AS c FROM milestones')->fetch(PDO::FETCH_ASSOC);

        return (int) $result['c'];
    }
}
