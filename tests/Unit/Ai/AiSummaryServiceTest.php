<?php

declare(strict_types=1);

namespace Diary\Tests\Unit\Ai;

use Diary\Access\OwnerId;
use Diary\Ai\AiSummaryService;
use Diary\Ai\ProgressSummary;
use Diary\Ai\ProviderError;
use Diary\Ai\TrendMetrics;
use Diary\Diary\DiaryEntryInput;
use Diary\Diary\DiaryEntryRepository;
use Diary\Diary\DiaryService;
use Diary\Milestone\MilestoneCategory;
use Diary\Milestone\MilestoneInput;
use Diary\Milestone\MilestoneRepository;
use Diary\Milestone\MilestoneService;
use Diary\Storage\ConnectionFactory;
use Diary\Storage\Crypto;
use Diary\Storage\KeyRing;
use Diary\Storage\PayloadCodec;
use Diary\Support\DateRange;
use Diary\Support\FixedClock;
use Diary\Support\LocalDate;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * AiSummaryService: input scoping and outcome precedence (Requirements
 * 9.1-9.5).
 *
 * Uses an in-memory SQLite schema standing in for migrations 003, 004 and 006,
 * following the pattern in tests/Unit/Diary/DiaryEntryRepositoryTest.php and
 * tests/Unit/Milestone/MilestoneRepositoryTest.php.
 */
final class AiSummaryServiceTest extends TestCase
{
    private PDO $pdo;
    private DiaryService $diaryService;
    private MilestoneService $milestoneService;
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

        $this->diaryService = new DiaryService(new DiaryEntryRepository($this->pdo, $codec));
        $this->milestoneService = new MilestoneService(new MilestoneRepository($this->pdo, $codec));
    }

    private function owner(string $suffix = '1'): OwnerId
    {
        return OwnerId::fromString('0' . str_repeat('1', 24) . $suffix);
    }

    private function entryInput(string $date, int $moodRating = 7): DiaryEntryInput
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

    private function milestoneInput(string $date, string $description = 'Started physiotherapy'): MilestoneInput
    {
        return MilestoneInput::of(LocalDate::fromString($date), $description, MilestoneCategory::Lifestyle);
    }

    private function service(FakeSummaryProvider $provider): AiSummaryService
    {
        return new AiSummaryService($this->diaryService, $this->milestoneService, $provider);
    }

    private function range(string $start, string $end): DateRange
    {
        return DateRange::of(LocalDate::fromString($start), LocalDate::fromString($end));
    }

    // --- Outcome precedence (Requirements 9.4, 9.5) ---

    public function testFewerThanThreeEntriesYieldsInsufficientDataEvenWhenTheProviderWouldSucceed(): void
    {
        $owner = $this->owner();
        $this->createEntries($owner, ['2025-03-01', '2025-03-02']);

        $provider = new FakeSummaryProvider();
        $provider->queue(new ProgressSummary('would have succeeded anyway', TrendMetrics::of(3, ...$this->stubStats())));

        $outcome = $this->service($provider)->summarise($owner, $this->range('2025-03-01', '2025-03-31'));

        self::assertTrue($outcome->isInsufficientData());
        self::assertSame(0, $provider->callCount());
    }

    public function testFewerThanThreeEntriesYieldsInsufficientDataEvenWhenTheProviderWouldAlsoFail(): void
    {
        $owner = $this->owner();
        $this->createEntries($owner, ['2025-03-01', '2025-03-02']);

        $provider = new FakeSummaryProvider();
        $provider->queueFailure(new ProviderError('would have failed anyway'));

        $outcome = $this->service($provider)->summarise($owner, $this->range('2025-03-01', '2025-03-31'));

        self::assertTrue($outcome->isInsufficientData());
        self::assertSame(0, $provider->callCount());
    }

    public function testZeroEntriesYieldsInsufficientData(): void
    {
        $owner = $this->owner();

        $provider = new FakeSummaryProvider();
        $outcome = $this->service($provider)->summarise($owner, $this->range('2025-03-01', '2025-03-31'));

        self::assertTrue($outcome->isInsufficientData());
        self::assertSame(0, $provider->callCount());
    }

    public function testExactlyThreeEntriesWithAWorkingProviderReturnsAFullSummary(): void
    {
        $owner = $this->owner();
        $this->createEntries($owner, ['2025-03-01', '2025-03-02', '2025-03-03']);

        $provider = new FakeSummaryProvider();
        $provider->queue(new ProgressSummary('Mood has been steady this month.', TrendMetrics::of(3, ...$this->stubStats())));

        $outcome = $this->service($provider)->summarise($owner, $this->range('2025-03-01', '2025-03-31'));

        self::assertTrue($outcome->isSummary());
        self::assertSame('Mood has been steady this month.', $outcome->summaryValue()->narrative());
        self::assertSame(1, $provider->callCount());
    }

    public function testThreeOrMoreEntriesWithAFailingProviderReturnsUnavailable(): void
    {
        $owner = $this->owner();
        $this->createEntries($owner, ['2025-03-01', '2025-03-02', '2025-03-03', '2025-03-04']);

        $provider = new FakeSummaryProvider();
        $provider->queueFailure(new ProviderError('provider unreachable'));

        $outcome = $this->service($provider)->summarise($owner, $this->range('2025-03-01', '2025-03-31'));

        self::assertTrue($outcome->isUnavailable());
        self::assertSame(1, $provider->callCount());
    }

    // --- Input scoping (Requirements 9.1, 9.3) ---

    public function testEntriesOutsideTheSelectedRangeAreNeverIncludedInTheProviderInput(): void
    {
        $owner = $this->owner();
        $this->createEntries($owner, ['2025-02-28', '2025-03-01', '2025-03-02', '2025-03-03', '2025-03-04', '2025-03-05']);

        $provider = new FakeSummaryProvider();
        $provider->queue(new ProgressSummary('narrative', TrendMetrics::of(3, ...$this->stubStats())));

        $this->service($provider)->summarise($owner, $this->range('2025-03-01', '2025-03-03'));

        $input = $provider->calls()[0];
        $dates = array_map(static fn ($e) => $e->date()->toIso(), $input->entries());
        self::assertSame(['2025-03-01', '2025-03-02', '2025-03-03'], $dates);
    }

    public function testEntriesBelongingToAnotherOwnerAreNeverIncludedInTheProviderInput(): void
    {
        $owner = $this->owner('1');
        $otherOwner = $this->owner('2');
        $this->createEntries($owner, ['2025-03-01', '2025-03-02', '2025-03-03']);
        $this->createEntries($otherOwner, ['2025-03-01', '2025-03-02', '2025-03-03', '2025-03-04']);

        $provider = new FakeSummaryProvider();
        $provider->queue(new ProgressSummary('narrative', TrendMetrics::of(3, ...$this->stubStats())));

        $this->service($provider)->summarise($owner, $this->range('2025-03-01', '2025-03-31'));

        $input = $provider->calls()[0];
        self::assertCount(3, $input->entries());
    }

    public function testMilestonesOutsideTheSelectedRangeAreNeverIncludedInTheProviderInput(): void
    {
        $owner = $this->owner();
        $this->createEntries($owner, ['2025-03-01', '2025-03-02', '2025-03-03']);
        $this->milestoneService->create($owner, $this->acceptedMilestoneValidation($this->milestoneInput('2025-02-15')), $this->clock);
        $this->milestoneService->create($owner, $this->acceptedMilestoneValidation($this->milestoneInput('2025-03-02', 'In range')), $this->clock);
        $this->milestoneService->create($owner, $this->acceptedMilestoneValidation($this->milestoneInput('2025-04-01')), $this->clock);

        $provider = new FakeSummaryProvider();
        $provider->queue(new ProgressSummary('narrative', TrendMetrics::of(3, ...$this->stubStats())));

        $this->service($provider)->summarise($owner, $this->range('2025-03-01', '2025-03-31'));

        $input = $provider->calls()[0];
        self::assertCount(1, $input->milestones());
        self::assertSame('In range', $input->milestones()[0]->description());
    }

    public function testMilestonesBelongingToAnotherOwnerAreNeverIncludedInTheProviderInput(): void
    {
        $owner = $this->owner('1');
        $otherOwner = $this->owner('2');
        $this->createEntries($owner, ['2025-03-01', '2025-03-02', '2025-03-03']);
        $this->milestoneService->create($otherOwner, $this->acceptedMilestoneValidation($this->milestoneInput('2025-03-02')), $this->clock);

        $provider = new FakeSummaryProvider();
        $provider->queue(new ProgressSummary('narrative', TrendMetrics::of(3, ...$this->stubStats())));

        $this->service($provider)->summarise($owner, $this->range('2025-03-01', '2025-03-31'));

        $input = $provider->calls()[0];
        self::assertCount(0, $input->milestones());
    }

    /**
     * @param list<string> $dates
     */
    private function createEntries(OwnerId $owner, array $dates): void
    {
        foreach ($dates as $date) {
            $this->diaryService->submitEntry(
                $owner,
                \Diary\Diary\DiaryValidation::accepted($this->entryInput($date), $this->entryInput($date)->toSubmittedAnswers()),
                $this->clock,
            );
        }
    }

    private function acceptedMilestoneValidation(MilestoneInput $input): \Diary\Milestone\MilestoneValidation
    {
        return \Diary\Milestone\MilestoneValidation::accepted(
            $input,
            \Diary\Milestone\MilestoneSubmission::of([
                'date' => $input->date()->toIso(),
                'description' => $input->description(),
                'category' => $input->category()->value,
            ])
        );
    }

    /**
     * @return array{0: \Diary\Ai\SeriesStats, 1: \Diary\Ai\SeriesStats}
     */
    private function stubStats(): array
    {
        $stats = \Diary\Ai\SeriesStats::of(3, 6.0, 5, 7, \Diary\Ai\TrendDirection::Stable);

        return [$stats, $stats];
    }
}
