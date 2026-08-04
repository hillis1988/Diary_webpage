<?php

declare(strict_types=1);

namespace Diary\Tests\Unit\Diary;

use Diary\Access\OwnerId;
use Diary\Diary\DiaryEntryInput;
use Diary\Diary\DiaryEntryRepository;
use Diary\Diary\DiaryService;
use Diary\Diary\DiaryValidation;
use Diary\Diary\SubmittedAnswers;
use Diary\Storage\ConnectionFactory;
use Diary\Storage\Crypto;
use Diary\Storage\KeyRing;
use Diary\Storage\PayloadCodec;
use Diary\Support\DateRange;
use Diary\Support\FixedClock;
use Diary\Support\LocalDate;
use Diary\Support\YearMonth;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * DiaryEntryRepository and DiaryService::submitEntry() (Requirements 5.3, 5.4,
 * 4.4).
 *
 * Uses an in-memory SQLite schema standing in for migrations 003 and 004
 * (SQLite cannot express MariaDB's exact UNIQUE KEY / FOREIGN KEY syntax, so
 * the schema below is hand-adapted, following the pattern in
 * tests/Unit/Auth/SqliteAuthTables.php and tests/Unit/Storage/PayloadCodecTest.php).
 */
final class DiaryEntryRepositoryTest extends TestCase
{
    private PDO $pdo;
    private DiaryEntryRepository $repository;
    private DiaryService $service;
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
            'CREATE TABLE diary_entries (
                id                 CHAR(26)   NOT NULL PRIMARY KEY,
                owner_id           CHAR(26)   NOT NULL,
                entry_date         DATE       NOT NULL,
                key_id             CHAR(26)   NOT NULL,
                nonce              BLOB       NOT NULL,
                payload_ciphertext BLOB       NOT NULL,
                created_at         DATETIME   NOT NULL,
                updated_at         DATETIME   NOT NULL,
                UNIQUE (owner_id, entry_date)
            )'
        );

        $this->clock = FixedClock::at('2025-03-01 09:30:00');

        $keyRing = new KeyRing($this->pdo, str_repeat("\x2a", KeyRing::KEY_LENGTH), $this->clock);
        $codec = new PayloadCodec(new Crypto($keyRing));

        $this->repository = new DiaryEntryRepository($this->pdo, $codec);
        $this->service = new DiaryService($this->repository);
    }

    private function owner(string $suffix = '1'): OwnerId
    {
        // A syntactically valid ULID (Crockford base32, no I/L/O/U): the suffix
        // is what distinguishes one owner from another across tests.
        return OwnerId::fromString('0' . str_repeat('1', 24) . $suffix);
    }

    private function input(string $date = '2025-03-01', int $moodRating = 7): DiaryEntryInput
    {
        return DiaryEntryInput::of(
            date: LocalDate::fromString($date),
            moodRating: $moodRating,
            sleepQuality: 3,
            events: 'Walked outside',
            thoughts: 'Felt okay',
            emotions: 'calm',
        );
    }

    private function acceptedValidation(DiaryEntryInput $input): DiaryValidation
    {
        return DiaryValidation::accepted($input, $input->toSubmittedAnswers());
    }

    public function testFirstSubmissionInsertsANewRow(): void
    {
        $owner = $this->owner();

        $entry = $this->repository->upsert($owner, $this->input(), $this->clock);

        self::assertSame(1, $this->countRows());
        self::assertNotSame('', $entry->id());

        $found = $this->repository->findByDate($owner, LocalDate::fromString('2025-03-01'));
        self::assertNotNull($found);
        self::assertSame($entry->id(), $found->id());
        self::assertSame(7, $found->input()->moodRating());
    }

    public function testSecondSubmissionForTheSameDateUpdatesRatherThanDuplicates(): void
    {
        $owner = $this->owner();

        $first = $this->repository->upsert($owner, $this->input(moodRating: 4), $this->clock);

        $this->clock->advanceMinutes(10);
        $second = $this->repository->upsert($owner, $this->input(moodRating: 9), $this->clock);

        self::assertSame(1, $this->countRows());
        self::assertSame($first->id(), $second->id());

        $found = $this->repository->findByDate($owner, LocalDate::fromString('2025-03-01'));
        self::assertNotNull($found);
        self::assertSame(9, $found->input()->moodRating());
        self::assertSame($first->id(), $found->id());
    }

    public function testDiaryServiceSubmitEntryOnlyWritesOnAcceptedValidation(): void
    {
        $owner = $this->owner();

        $rejected = DiaryValidation::rejected(
            SubmittedAnswers::blank(),
            'diary_entry_invalid',
            ['mood_rating' => 'Please provide your mood rating.']
        );

        $result = $this->service->submitEntry($owner, $rejected, $this->clock);

        self::assertTrue($result->isFailure());
        self::assertSame(0, $this->countRows());

        $accepted = $this->acceptedValidation($this->input());
        $result = $this->service->submitEntry($owner, $accepted, $this->clock);

        self::assertTrue($result->isOk());
        self::assertSame(1, $this->countRows());
    }

    public function testFindByDateReturnsNullWhenThereIsNoEntry(): void
    {
        $owner = $this->owner();

        self::assertNull($this->repository->findByDate($owner, LocalDate::fromString('2025-03-01')));
    }

    public function testFindInRangeReturnsOnlyTheOwnersEntriesOrderedByDate(): void
    {
        $ownerA = $this->owner('1');
        $ownerB = $this->owner('2');

        $this->repository->upsert($ownerA, $this->input(date: '2025-03-05', moodRating: 5), $this->clock);
        $this->repository->upsert($ownerA, $this->input(date: '2025-03-01', moodRating: 6), $this->clock);
        $this->repository->upsert($ownerA, $this->input(date: '2025-03-10', moodRating: 7), $this->clock);
        $this->repository->upsert($ownerB, $this->input(date: '2025-03-03', moodRating: 1), $this->clock);

        $range = DateRange::of(LocalDate::fromString('2025-03-01'), LocalDate::fromString('2025-03-10'));
        $entries = $this->repository->findInRange($ownerA, $range);

        self::assertCount(3, $entries);
        self::assertSame(
            ['2025-03-01', '2025-03-05', '2025-03-10'],
            array_map(static fn ($e) => $e->date()->toIso(), $entries)
        );

        foreach ($entries as $entry) {
            self::assertTrue($entry->ownerId()->equals($ownerA));
        }
    }

    public function testDatesWithEntriesReturnsOnlyTheOwnersDatesWithinTheMonth(): void
    {
        $ownerA = $this->owner('1');
        $ownerB = $this->owner('2');

        $this->repository->upsert($ownerA, $this->input(date: '2025-03-01'), $this->clock);
        $this->repository->upsert($ownerA, $this->input(date: '2025-03-15'), $this->clock);
        $this->repository->upsert($ownerA, $this->input(date: '2025-04-01'), $this->clock);
        $this->repository->upsert($ownerB, $this->input(date: '2025-03-20'), $this->clock);

        $dates = $this->repository->datesWithEntries($ownerA, YearMonth::of(2025, 3));

        self::assertSame(
            ['2025-03-01', '2025-03-15'],
            array_map(static fn (LocalDate $d) => $d->toIso(), $dates)
        );
    }

    public function testEncryptedPayloadRoundTripsThroughFreeTextFields(): void
    {
        $owner = $this->owner();

        $this->repository->upsert($owner, $this->input(), $this->clock);

        $row = $this->pdo->query('SELECT payload_ciphertext FROM diary_entries')->fetch(PDO::FETCH_ASSOC);
        self::assertStringNotContainsString('Walked outside', (string) $row['payload_ciphertext']);

        $found = $this->repository->findByDate($owner, LocalDate::fromString('2025-03-01'));
        self::assertNotNull($found);
        self::assertSame('Walked outside', $found->input()->events());
        self::assertSame('Felt okay', $found->input()->thoughts());
        self::assertSame('calm', $found->input()->emotions());
        self::assertSame(3, $found->input()->sleepQuality());
    }

    private function countRows(): int
    {
        $result = $this->pdo->query('SELECT COUNT(*) AS c FROM diary_entries')->fetch(PDO::FETCH_ASSOC);

        return (int) $result['c'];
    }
}
