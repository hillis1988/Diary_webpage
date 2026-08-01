<?php

declare(strict_types=1);

namespace Diary\Tests\Unit\Diary;

use Diary\Access\OwnerId;
use Diary\Diary\CalendarService;
use Diary\Diary\DiaryEntryInput;
use Diary\Diary\DiaryEntryRepository;
use Diary\Milestone\MilestoneCategory;
use Diary\Milestone\MilestoneInput;
use Diary\Milestone\MilestoneRepository;
use Diary\Storage\ConnectionFactory;
use Diary\Storage\Crypto;
use Diary\Storage\KeyRing;
use Diary\Storage\PayloadCodec;
use Diary\Support\FixedClock;
use Diary\Support\LocalDate;
use Diary\Support\YearMonth;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * CalendarService::calendarMonth() (Requirements 8.1, 8.3, 8.5).
 *
 * Uses an in-memory SQLite schema standing in for migrations 004 and 006,
 * following the pattern in tests/Unit/Diary/DiaryEntryRepositoryTest.php and
 * tests/Unit/Milestone/MilestoneRepositoryTest.php.
 */
final class CalendarServiceTest extends TestCase
{
    private PDO $pdo;
    private FixedClock $clock;
    private DiaryEntryRepository $diaryEntries;
    private MilestoneRepository $milestones;
    private CalendarService $calendarService;

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

        $this->diaryEntries = new DiaryEntryRepository($this->pdo, $codec);
        $this->milestones = new MilestoneRepository($this->pdo, $codec);
        $this->calendarService = new CalendarService($this->diaryEntries, $this->milestones);
    }

    private function owner(string $suffix = '1'): OwnerId
    {
        return OwnerId::fromString('0' . str_repeat('1', 24) . $suffix);
    }

    private function addEntry(OwnerId $owner, string $date): void
    {
        $this->diaryEntries->upsert(
            $owner,
            DiaryEntryInput::of(date: LocalDate::fromString($date), moodRating: 5),
            $this->clock,
        );
    }

    private function addMilestone(OwnerId $owner, string $date): void
    {
        $this->milestones->create(
            $owner,
            MilestoneInput::of(LocalDate::fromString($date), 'Something notable', MilestoneCategory::Other),
            $this->clock,
        );
    }

    public function testAMonthWithNeitherEntriesNorMilestonesHasNoIndicators(): void
    {
        $owner = $this->owner();

        $calendarMonth = $this->calendarService->calendarMonth($owner, YearMonth::of(2025, 3));

        self::assertSame([], $calendarMonth->entryDates());
        self::assertSame([], $calendarMonth->milestoneDates());
        self::assertFalse($calendarMonth->hasEntryOn(LocalDate::fromString('2025-03-10')));
        self::assertFalse($calendarMonth->hasMilestoneOn(LocalDate::fromString('2025-03-10')));
    }

    public function testAMonthWithOnlyEntriesShowsOnlyEntryIndicators(): void
    {
        $owner = $this->owner();
        $this->addEntry($owner, '2025-03-05');
        $this->addEntry($owner, '2025-03-20');

        $calendarMonth = $this->calendarService->calendarMonth($owner, YearMonth::of(2025, 3));

        self::assertSame(['2025-03-05', '2025-03-20'], array_map(static fn (LocalDate $d) => $d->toIso(), $calendarMonth->entryDates()));
        self::assertSame([], $calendarMonth->milestoneDates());
        self::assertTrue($calendarMonth->hasEntryOn(LocalDate::fromString('2025-03-05')));
        self::assertFalse($calendarMonth->hasMilestoneOn(LocalDate::fromString('2025-03-05')));
    }

    public function testAMonthWithOnlyMilestonesShowsOnlyMilestoneIndicators(): void
    {
        $owner = $this->owner();
        $this->addMilestone($owner, '2025-03-12');

        $calendarMonth = $this->calendarService->calendarMonth($owner, YearMonth::of(2025, 3));

        self::assertSame([], $calendarMonth->entryDates());
        self::assertSame(['2025-03-12'], array_map(static fn (LocalDate $d) => $d->toIso(), $calendarMonth->milestoneDates()));
        self::assertFalse($calendarMonth->hasEntryOn(LocalDate::fromString('2025-03-12')));
        self::assertTrue($calendarMonth->hasMilestoneOn(LocalDate::fromString('2025-03-12')));
    }

    public function testAMonthWithBothEntriesAndMilestonesShowsBothIndicatorsIncludingOnTheSameDate(): void
    {
        $owner = $this->owner();
        $this->addEntry($owner, '2025-03-15');
        $this->addMilestone($owner, '2025-03-15');
        $this->addMilestone($owner, '2025-03-22');

        $calendarMonth = $this->calendarService->calendarMonth($owner, YearMonth::of(2025, 3));

        self::assertTrue($calendarMonth->hasEntryOn(LocalDate::fromString('2025-03-15')));
        self::assertTrue($calendarMonth->hasMilestoneOn(LocalDate::fromString('2025-03-15')));
        self::assertTrue($calendarMonth->hasMilestoneOn(LocalDate::fromString('2025-03-22')));
        self::assertFalse($calendarMonth->hasEntryOn(LocalDate::fromString('2025-03-22')));
    }

    public function testIndicatorsAreScopedToTheResolvedOwnerOnly(): void
    {
        $ownerA = $this->owner('1');
        $ownerB = $this->owner('2');

        $this->addEntry($ownerB, '2025-03-05');
        $this->addMilestone($ownerB, '2025-03-06');

        $calendarMonth = $this->calendarService->calendarMonth($ownerA, YearMonth::of(2025, 3));

        self::assertSame([], $calendarMonth->entryDates());
        self::assertSame([], $calendarMonth->milestoneDates());
        self::assertFalse($calendarMonth->hasEntryOn(LocalDate::fromString('2025-03-05')));
        self::assertFalse($calendarMonth->hasMilestoneOn(LocalDate::fromString('2025-03-06')));
    }

    public function testDatesOutsideTheRequestedMonthAreExcluded(): void
    {
        $owner = $this->owner();
        $this->addEntry($owner, '2025-02-28');
        $this->addEntry($owner, '2025-04-01');
        $this->addMilestone($owner, '2025-02-01');

        $calendarMonth = $this->calendarService->calendarMonth($owner, YearMonth::of(2025, 3));

        self::assertSame([], $calendarMonth->entryDates());
        self::assertSame([], $calendarMonth->milestoneDates());
    }
}
