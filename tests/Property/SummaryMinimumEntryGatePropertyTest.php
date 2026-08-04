<?php

declare(strict_types=1);

namespace Diary\Tests\Property;

use Diary\Access\OwnerId;
use Diary\Ai\AiSummaryService;
use Diary\Ai\CbtAdvice;
use Diary\Ai\ProgressSummary;
use Diary\Ai\ProviderError;
use Diary\Ai\SeriesStats;
use Diary\Ai\TrendDirection;
use Diary\Ai\TrendMetrics;
use Diary\Diary\DiaryEntryInput;
use Diary\Diary\DiaryEntryRepository;
use Diary\Diary\DiaryService;
use Diary\Diary\DiaryValidation;
use Diary\Milestone\MilestoneRepository;
use Diary\Milestone\MilestoneService;
use Diary\Storage\ConnectionFactory;
use Diary\Storage\Crypto;
use Diary\Storage\KeyRing;
use Diary\Storage\PayloadCodec;
use Diary\Support\DateRange;
use Diary\Support\FixedClock;
use Diary\Support\LocalDate;
use Eris\Generator;
use Eris\TestTrait;
use PDO;
use PHPUnit\Framework\TestCase;
use Diary\Tests\Unit\Ai\FakeSummaryProvider;

/**
 * Property 14: The 3-entry minimum gates payload building and provider
 * invocation, and takes precedence over any provider outcome.
 *
 * For any number of Diary_Entry records N in [0, 8] belonging to the
 * owner within the selected range, and for any provider behaviour (queued
 * to either succeed or raise a ProviderError):
 *
 *   - if N < 3, AiSummaryService::summarise() always returns
 *     InsufficientData, regardless of the queued provider behaviour, and
 *     the provider's generate() is never called (callCount() === 0) - the
 *     gate happens strictly before any payload is built or the provider is
 *     invoked;
 *   - if N >= 3, the provider IS invoked exactly once (callCount() === 1),
 *     and the outcome matches whatever the provider was configured to do:
 *     Summary on a queued success, Unavailable on a queued ProviderError.
 *
 * Uses the same in-memory SQLite fixture pattern as
 * {@see \Diary\Tests\Unit\Ai\AiSummaryServiceTest}, since real Diary_Entry
 * rows (not stand-in doubles) are required to exercise
 * DiaryService::entriesInRange() ahead of the gate. Capped at 100
 * iterations with N in [0, 8]: creating up to 8 diary entries through the
 * real DiaryService (full encrypt/decrypt round-trip) per iteration is the
 * dominant cost, and 8 already covers one iteration below, at, and well
 * above the 3-entry threshold.
 *
 * Requirements: 11.1, 11.2, 12.1, 12.2.
 */
final class SummaryMinimumEntryGatePropertyTest extends TestCase
{
    use TestTrait;

    private const MINIMUM_ENTRIES = 3;
    private const MAX_ENTRY_COUNT = 8;

    protected function setUp(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_sqlite is not loaded.');
        }
    }

    // Feature: summary-json-payload, Property 14: The 3-entry minimum gates payload building and provider invocation, and takes precedence over any provider outcome
    public function testMinimumEntryGateTakesPrecedenceOverProviderOutcome(): void
    {
        $this->limitTo(100)
            ->forAll(
                Generator\choose(0, self::MAX_ENTRY_COUNT),
                Generator\elements(['succeed', 'fail'])
            )
            ->then(function (int $entryCount, string $providerBehaviour): void {
                [$diaryService, $milestoneService, $clock] = $this->newHarness();

                $owner = self::owner();
                $this->createEntries($diaryService, $owner, $clock, $entryCount);

                $provider = new FakeSummaryProvider();
                if ($providerBehaviour === 'succeed') {
                    $provider->queue(self::stubSummary());
                } else {
                    $provider->queueFailure(new ProviderError('provider unreachable'));
                }

                $service = new AiSummaryService($diaryService, $milestoneService, $provider);
                $range = DateRange::of(LocalDate::fromString('2025-03-01'), LocalDate::fromString('2025-03-31'));

                $outcome = $service->summarise($owner, $range);

                if ($entryCount < self::MINIMUM_ENTRIES) {
                    self::assertTrue(
                        $outcome->isInsufficientData(),
                        "With {$entryCount} entries (below the minimum), the outcome must always be InsufficientData, "
                            . "regardless of the provider being configured to {$providerBehaviour}."
                    );
                    self::assertSame(
                        0,
                        $provider->callCount(),
                        "With {$entryCount} entries (below the minimum), the provider must never be invoked."
                    );
                } else {
                    self::assertSame(
                        1,
                        $provider->callCount(),
                        "With {$entryCount} entries (at/above the minimum), the provider must be invoked exactly once."
                    );

                    if ($providerBehaviour === 'succeed') {
                        self::assertTrue(
                            $outcome->isSummary(),
                            "With {$entryCount} entries and a succeeding provider, the outcome must be Summary."
                        );
                    } else {
                        self::assertTrue(
                            $outcome->isUnavailable(),
                            "With {$entryCount} entries and a failing provider, the outcome must be Unavailable."
                        );
                    }
                }
            });
    }

    private function createEntries(DiaryService $diaryService, OwnerId $owner, FixedClock $clock, int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            $date = LocalDate::fromString('2025-03-01')->plusDays($i);
            $input = DiaryEntryInput::of(
                date: $date,
                moodRating: 7,
                sleepQuality: 3,
                events: 'Walked outside',
                thoughts: 'Felt okay',
                emotions: 'calm',
            );

            $diaryService->submitEntry(
                $owner,
                DiaryValidation::accepted($input, $input->toSubmittedAnswers()),
                $clock,
            );
        }
    }

    private static function owner(): OwnerId
    {
        return OwnerId::fromString('0' . str_repeat('1', 25));
    }

    private static function stubSummary(): ProgressSummary
    {
        $stats = SeriesStats::of(3, 6.0, 5, 7, TrendDirection::Stable);
        $advice = new CbtAdvice('pattern', 'distortions', 'balanced perspective', 'next action');

        return new ProgressSummary('a narrative', $advice, TrendMetrics::of(3, $stats, $stats));
    }

    /**
     * @return array{0: DiaryService, 1: MilestoneService, 2: FixedClock}
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

        $clock = FixedClock::at('2025-03-01 09:30:00');
        $keyRing = new KeyRing($pdo, str_repeat("\x2a", KeyRing::KEY_LENGTH), $clock);
        $codec = new PayloadCodec(new Crypto($keyRing));

        $diaryService = new DiaryService(new DiaryEntryRepository($pdo, $codec));
        $milestoneService = new MilestoneService(new MilestoneRepository($pdo, $codec));

        return [$diaryService, $milestoneService, $clock];
    }
}
