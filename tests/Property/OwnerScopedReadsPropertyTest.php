<?php

declare(strict_types=1);

namespace Diary\Tests\Property;

use Diary\Access\AccessControlService;
use Diary\Access\OwnerId;
use Diary\Ai\CbtRecommendation;
use Diary\Ai\CbtRecommendationRepository;
use Diary\Auth\ContextRole;
use Diary\Auth\Session;
use Diary\Auth\SessionId;
use Diary\Auth\SecurityContext;
use Diary\Auth\UserId;
use Diary\Auth\UserRole;
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
use Diary\Support\DateRange;
use Diary\Support\FixedClock;
use Diary\Support\LocalDate;
use Diary\Support\YearMonth;
use Eris\Generator;
use Eris\Generators;
use Eris\TestTrait;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Property 2: Reads are scoped to the session's data owner.
 *
 * design.md states the full property as: "For any set of Primary_Users with
 * entries, recommendations and milestones, and for any set of viewers linked
 * to them, every read operation returns only data belonging to the
 * requesting session's resolved data owner; active viewers receive that
 * owner's entries, recommendations, milestones, calendar and summary, and
 * revoked viewers receive nothing."
 *
 * ## Scoping decision - the "revoked viewers receive nothing" half
 *
 * That half is not a repository-scoping property at all: revocation
 * (task 15.1's `AuthService`/`ViewerAccessService`) terminates every one of
 * the revoked account's live sessions immediately, so a revoked viewer has no
 * session left to build a {@see SecurityContext} from and never reaches a
 * repository call in the first place. That is a session-resolution property,
 * already covered by
 * {@see \Diary\Tests\Unit\Access\ViewerAccessServiceTest::testARevokedViewerSessionNoLongerResolves()}
 * and
 * {@see \Diary\Tests\Unit\Access\ViewerAccessServiceTest::testARevokedViewerCannotReAuthenticate()}.
 * Constructing a {@see SecurityContext} for a revoked account directly would
 * not exercise anything real - no code path ever builds one for a session
 * that does not exist - so this test does not attempt to model it. This
 * mirrors how {@see OneEntryPerDatePropertyTest} and
 * {@see TrendMetricsPropertyTest} each documented a half of their property
 * that depended on a collaborator out of scope for the test.
 *
 * This test is therefore scoped to the other half, which *is* a
 * repository-scoping property: for any set of Primary_Users each with their
 * own entries, milestones and recommendations, and for a session belonging
 * either to one of those owners or to one of their still-active viewers, the
 * resolved data owner is exactly the intended one, and every read
 * ({@see DiaryEntryRepository::findInRange()},
 * {@see DiaryEntryRepository::datesWithEntries()},
 * {@see MilestoneRepository::inRange()}, {@see CalendarService::calendarMonth()},
 * and {@see CbtRecommendationRepository::findByEntryId()} for each returned
 * entry) returns only that owner's data - never another Primary_User's, even
 * when their content happens to share a calendar date. AI_Summary_Service is
 * not separately exercised: it gathers its input through
 * `DiaryEntryRepository::findInRange()` and `MilestoneRepository::inRange()`
 * with no extra scoping logic of its own, so the same repository calls this
 * test already drives are what would leak if summary reads were ever
 * unscoped.
 *
 * Every read is issued against a {@see SecurityContext} built exactly the way
 * a real session builds one, via {@see SecurityContext::forSession()}, and
 * the scope handed to every repository call is
 * {@see AccessControlService::resolveDataOwner()} - the single sanctioned
 * source of owner scoping (Requirement 4.4) - never an owner id assembled by
 * hand in the test.
 *
 * Leakage is detected by content, not just by date: every generated entry's
 * free-text `events` field and every milestone's description embed the
 * owning Primary_User's index, and every recommendation's positive focus and
 * suggested change embed the owning entry's owner index and date. Two
 * different owners may coincidentally have an entry or milestone on the same
 * calendar date - the generator does not avoid this - so matching on content
 * as well as on date is what would catch a query that silently returned the
 * wrong owner's row for a colliding date rather than the intended owner's.
 *
 * Requirements: 4.4, 7.2, 8.5.
 */
final class OwnerScopedReadsPropertyTest extends TestCase
{
    use TestTrait;

    private const CLOCK_START = '2025-01-01 10:00:00';

    /** All generated dates fall within this month, so calendarMonth() has something to check against findInRange(). */
    private const BASE_DATE = '2025-01-01';
    private const DATE_POOL_MAX_OFFSET = 19; // 2025-01-01 .. 2025-01-20, all inside January.
    private const MONTH = '2025-01';

    protected function setUp(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_sqlite is not loaded.');
        }
    }

    // Feature: mental-health-diary, Property 2: Reads are scoped to the session's data owner
    public function testReadsAreScopedToTheSessionsResolvedDataOwner(): void
    {
        $this->limitTo(100)
            ->forAll(self::scenario())
            ->then(function (array $scenario): void {
                ['owners' => $owners, 'targetIndex' => $targetIndex, 'readerIsViewer' => $readerIsViewer] = $scenario;

                [$pdo, $diaryEntries, $milestones, $recommendations, $calendar, $clock] = $this->newHarness();

                // Populate every owner's data, tracking exactly what belongs to
                // each so leakage can be asserted against ground truth, not
                // against what the query happens to give back.
                $expectedEntriesByOwner = [];
                $expectedMilestonesByOwner = [];

                foreach ($owners as $ownerIndex => $ownerData) {
                    $ownerNumber = $ownerIndex + 1;
                    $owner = self::ownerId($ownerNumber);

                    $expectedEntries = [];
                    foreach ($ownerData['entries'] as ['offset' => $offset, 'mood' => $mood]) {
                        $date = self::baseDate()->plusDays($offset);
                        $marker = sprintf('owner%d-entry-%s', $ownerNumber, $date->toIso());

                        $entry = $diaryEntries->upsert(
                            $owner,
                            DiaryEntryInput::of(date: $date, moodRating: $mood, events: $marker),
                            $clock,
                        );

                        $recommendations->recordSuccess(
                            $entry->id(),
                            new CbtRecommendation(
                                sprintf('owner%d-focus-%s', $ownerNumber, $date->toIso()),
                                sprintf('owner%d-change-%s', $ownerNumber, $date->toIso()),
                            ),
                            'test-provider',
                            'test-model',
                            $clock->now(),
                            $clock,
                        );

                        $expectedEntries[$date->toIso()] = ['mood' => $mood, 'events' => $marker];
                    }
                    $expectedEntriesByOwner[$ownerNumber] = $expectedEntries;

                    $expectedMilestones = [];
                    foreach ($ownerData['milestones'] as ['offset' => $offset, 'category' => $category, 'suffix' => $suffix]) {
                        $date = self::baseDate()->plusDays($offset);
                        $description = sprintf('owner%d-milestone-%d', $ownerNumber, $suffix);

                        $milestones->create(
                            $owner,
                            MilestoneInput::of($date, $description, $category),
                            $clock,
                        );

                        $expectedMilestones[] = ['date' => $date->toIso(), 'description' => $description, 'category' => $category->value];
                    }
                    $expectedMilestonesByOwner[$ownerNumber] = $expectedMilestones;
                }

                $targetNumber = $targetIndex + 1;
                $targetOwner = self::ownerId($targetNumber);

                $ctx = self::securityContextFor($targetOwner, $readerIsViewer);

                $access = new AccessControlService($clock);
                $resolvedOwner = $access->resolveDataOwner($ctx);

                self::assertTrue(
                    $resolvedOwner->equals($targetOwner),
                    'resolveDataOwner must resolve to exactly the intended Primary_User, not another one'
                );

                $range = DateRange::of(self::baseDate(), self::baseDate()->plusDays(self::DATE_POOL_MAX_OFFSET));

                // --- DiaryEntryRepository::findInRange() ---
                $foundEntries = $diaryEntries->findInRange($resolvedOwner, $range);
                self::assertEntriesMatchExactly($expectedEntriesByOwner[$targetNumber], $resolvedOwner, $foundEntries);

                // --- DiaryEntryRepository::datesWithEntries() ---
                $datesWithEntries = $diaryEntries->datesWithEntries($resolvedOwner, YearMonth::fromString(self::MONTH));
                self::assertSame(
                    array_keys($expectedEntriesByOwner[$targetNumber]),
                    array_map(static fn (LocalDate $d): string => $d->toIso(), $datesWithEntries),
                    'datesWithEntries() must return exactly the resolved owner\'s own entry dates'
                );

                // --- MilestoneRepository::inRange() ---
                $foundMilestones = $milestones->inRange($resolvedOwner, $range);
                self::assertMilestonesMatchExactly($expectedMilestonesByOwner[$targetNumber], $resolvedOwner, $foundMilestones);

                // --- CalendarService::calendarMonth() ---
                $calendarMonth = $calendar->calendarMonth($resolvedOwner, YearMonth::fromString(self::MONTH));
                self::assertSame(
                    array_keys($expectedEntriesByOwner[$targetNumber]),
                    array_map(static fn (LocalDate $d): string => $d->toIso(), $calendarMonth->entryDates()),
                    'calendarMonth() entry indicators must come only from the resolved owner'
                );
                $expectedMilestoneDates = array_unique(array_map(
                    static fn (array $m): string => $m['date'],
                    $expectedMilestonesByOwner[$targetNumber]
                ));
                sort($expectedMilestoneDates);
                self::assertSame(
                    array_values($expectedMilestoneDates),
                    array_map(static fn (LocalDate $d): string => $d->toIso(), $calendarMonth->milestoneDates()),
                    'calendarMonth() milestone indicators must come only from the resolved owner'
                );

                // --- CbtRecommendationRepository::findByEntryId(), keyed off an owner-scoped entry lookup ---
                foreach ($foundEntries as $entry) {
                    $record = $recommendations->findByEntryId($entry->id());
                    self::assertNotNull($record, 'every generated entry has a recommendation attached in this scenario');
                    self::assertTrue($record->isGenerated());

                    $dateIso = $entry->date()->toIso();
                    self::assertSame(
                        sprintf('owner%d-focus-%s', $targetNumber, $dateIso),
                        $record->recommendation()->positiveFocus(),
                        'the recommendation for the resolved owner\'s entry must be that owner\'s own, never another owner\'s'
                    );
                    self::assertSame(
                        sprintf('owner%d-change-%s', $targetNumber, $dateIso),
                        $record->recommendation()->suggestedChange(),
                    );
                }
            });
    }

    /**
     * @param array<string, array{mood: int, events: string}> $expected keyed by ISO date
     * @param list<\Diary\Diary\DiaryEntry>                    $found
     */
    private static function assertEntriesMatchExactly(array $expected, OwnerId $resolvedOwner, array $found): void
    {
        self::assertSame(
            count($expected),
            count($found),
            'findInRange() must return exactly one row per the resolved owner\'s own entry, no more and no fewer'
        );

        $foundByDate = [];
        foreach ($found as $entry) {
            self::assertTrue($entry->ownerId()->equals($resolvedOwner), 'every returned entry must belong to the resolved owner');
            $foundByDate[$entry->date()->toIso()] = $entry;
        }

        foreach ($expected as $dateIso => $expectedRow) {
            self::assertArrayHasKey($dateIso, $foundByDate, "the resolved owner's own entry for {$dateIso} must be present");
            $entry = $foundByDate[$dateIso];
            self::assertSame($expectedRow['mood'], $entry->input()->moodRating(), "mood on {$dateIso} must be the resolved owner's own");
            self::assertSame(
                $expectedRow['events'],
                $entry->input()->events(),
                "the events marker on {$dateIso} must be the resolved owner's own, never another owner's content"
            );
        }
    }

    /**
     * @param list<array{date: string, description: string, category: string}> $expected
     * @param list<\Diary\Milestone\Milestone>                                  $found
     */
    private static function assertMilestonesMatchExactly(array $expected, OwnerId $resolvedOwner, array $found): void
    {
        self::assertSame(
            count($expected),
            count($found),
            'inRange() must return exactly the resolved owner\'s own milestones, no more and no fewer'
        );

        foreach ($found as $milestone) {
            self::assertTrue($milestone->ownerId()->equals($resolvedOwner), 'every returned milestone must belong to the resolved owner');
        }

        $expectedTuples = array_map(
            static fn (array $m): string => $m['date'] . '|' . $m['description'] . '|' . $m['category'],
            $expected
        );
        $foundTuples = array_map(
            static fn (\Diary\Milestone\Milestone $m): string => $m->date()->toIso() . '|' . $m->description() . '|' . $m->category()->value,
            $found
        );

        sort($expectedTuples);
        sort($foundTuples);

        self::assertSame(
            $expectedTuples,
            $foundTuples,
            'the resolved owner\'s milestones must match exactly, including on a date shared with another owner'
        );
    }

    /**
     * A {@see SecurityContext} built exactly the way a real session builds
     * one, via {@see SecurityContext::forSession()}: an owner context for the
     * target Primary_User themselves, or an active-viewer context whose
     * session was created for the target owner (Requirement 7.2's "a viewer
     * receives only their linked owner's data").
     */
    private static function securityContextFor(OwnerId $targetOwner, bool $readerIsViewer): SecurityContext
    {
        $now = FixedClock::at(self::CLOCK_START)->now();
        $dataOwnerId = $targetOwner->toUserId();
        $sessionUserId = $readerIsViewer ? self::viewerUserId(1) : $dataOwnerId;

        $session = new Session(
            id: SessionId::fromString(str_repeat('a', SessionId::LENGTH)),
            userId: $sessionUserId,
            contextRole: $readerIsViewer ? UserRole::Viewer : UserRole::Owner,
            dataOwnerId: $dataOwnerId,
            createdAt: $now,
            lastActivityAt: $now,
        );

        return SecurityContext::forSession($session);
    }

    private static function ownerId(int $number): OwnerId
    {
        return OwnerId::fromString('0' . str_repeat('1', 24) . (string) $number);
    }

    private static function viewerUserId(int $number): UserId
    {
        return UserId::fromString('0' . str_repeat('2', 24) . (string) $number);
    }

    private static function baseDate(): LocalDate
    {
        return LocalDate::fromString(self::BASE_DATE);
    }

    /**
     * @return array{0: PDO, 1: DiaryEntryRepository, 2: MilestoneRepository, 3: CbtRecommendationRepository, 4: CalendarService, 5: FixedClock}
     */
    private function newHarness(): array
    {
        $pdo = ConnectionFactory::fromDsn('sqlite::memory:');

        $pdo->exec(
            'CREATE TABLE encryption_keys (
                id          CHAR(26)   NOT NULL PRIMARY KEY,
                wrapped_dek BLOB       NOT NULL,
                wrap_nonce  BLOB       NOT NULL,
                created_at  DATETIME   NOT NULL,
                retired_at  DATETIME   NULL
            )'
        );

        $pdo->exec(
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

        $pdo->exec(
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

        $pdo->exec(
            'CREATE TABLE cbt_recommendations (
                id                 CHAR(26)    NOT NULL PRIMARY KEY,
                entry_id           CHAR(26)    NOT NULL,
                status             VARCHAR(16) NOT NULL,
                key_id             CHAR(26)    NULL,
                nonce              BLOB        NULL,
                payload_ciphertext BLOB        NULL,
                provider           VARCHAR(64) NOT NULL,
                model              VARCHAR(64) NOT NULL,
                attempt_count      INTEGER     NOT NULL,
                generated_at       DATETIME    NULL,
                UNIQUE (entry_id)
            )'
        );

        $clock = FixedClock::at(self::CLOCK_START);
        $keyRing = new KeyRing($pdo, str_repeat("\x2a", KeyRing::KEY_LENGTH), $clock);
        $codec = new PayloadCodec(new Crypto($keyRing));

        $diaryEntries = new DiaryEntryRepository($pdo, $codec);
        $milestones = new MilestoneRepository($pdo, $codec);
        $recommendations = new CbtRecommendationRepository($pdo, $codec);
        $calendar = new CalendarService($diaryEntries, $milestones);

        return [$pdo, $diaryEntries, $milestones, $recommendations, $calendar, $clock];
    }

    /**
     * Two to four Primary_Users, each with their own random entries and
     * milestones, plus which one a reading session targets and whether that
     * session is the owner's own or an active viewer's.
     *
     * @return \Eris\Generator
     */
    private static function scenario(): \Eris\Generator
    {
        return Generator\bind(
            Generator\choose(2, 4),
            static fn (int $ownersCount): \Eris\Generator => Generator\map(
                static fn (array $parts): array => [
                    'owners' => $parts[0],
                    'targetIndex' => $parts[1],
                    'readerIsViewer' => $parts[2],
                ],
                Generator\tuple(
                    Generator\vector($ownersCount, self::ownerData()),
                    Generator\choose(0, $ownersCount - 1),
                    Generator\elements([true, false])
                )
            )
        );
    }

    /**
     * @return \Eris\Generator array{entries: list<array{offset: int, mood: int}>,
     *                                milestones: list<array{offset: int, category: MilestoneCategory, suffix: int}>}
     */
    private static function ownerData(): \Eris\Generator
    {
        return Generator\map(
            static fn (array $parts): array => ['entries' => $parts[0], 'milestones' => $parts[1]],
            Generator\tuple(self::entriesData(), self::milestonesData())
        );
    }

    /**
     * A subset of the date pool (so at most one entry per date, matching the
     * real one-entry-per-date constraint), each with an independent mood
     * rating.
     *
     * @return \Eris\Generator list<array{offset: int, mood: int}>
     */
    private static function entriesData(): \Eris\Generator
    {
        $pool = range(0, self::DATE_POOL_MAX_OFFSET);

        return Generator\bind(
            Generators::subset($pool),
            static function (array $offsets): \Eris\Generator {
                $offsets = array_values($offsets);
                $count = count($offsets);

                if ($count === 0) {
                    return Generator\constant([]);
                }

                return Generator\map(
                    static function (array $moods) use ($offsets): array {
                        $rows = [];
                        foreach ($offsets as $i => $offset) {
                            $rows[] = ['offset' => $offset, 'mood' => $moods[$i]];
                        }

                        return $rows;
                    },
                    Generator\vector($count, Generator\choose(1, 10))
                );
            }
        );
    }

    /**
     * Zero to five milestones; unlike entries, several may share a date.
     *
     * @return \Eris\Generator list<array{offset: int, category: MilestoneCategory, suffix: int}>
     */
    private static function milestonesData(): \Eris\Generator
    {
        return Generator\bind(
            Generator\choose(0, 5),
            static fn (int $count): \Eris\Generator => $count === 0
                ? Generator\constant([])
                : Generator\vector($count, self::milestoneRow())
        );
    }

    /**
     * @return \Eris\Generator array{offset: int, category: MilestoneCategory, suffix: int}
     */
    private static function milestoneRow(): \Eris\Generator
    {
        return Generator\map(
            static fn (array $parts): array => ['offset' => $parts[0], 'category' => $parts[1], 'suffix' => $parts[2]],
            Generator\tuple(
                Generator\choose(0, self::DATE_POOL_MAX_OFFSET),
                Generator\elements(MilestoneCategory::cases()),
                Generator\choose(0, 999_999)
            )
        );
    }
}
