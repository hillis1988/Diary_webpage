<?php

declare(strict_types=1);

namespace Diary\Tests\Property;

use Diary\Access\OwnerId;
use Diary\Diary\DiaryEntryRepository;
use Diary\Diary\DiaryInputValidator;
use Diary\Diary\DiaryService;
use Diary\Diary\QuestionSet;
use Diary\Diary\SubmittedAnswers;
use Diary\Storage\ConnectionFactory;
use Diary\Storage\Crypto;
use Diary\Storage\KeyRing;
use Diary\Storage\PayloadCodec;
use Diary\Support\FixedClock;
use Diary\Support\LocalDate;
use Eris\Generator;
use Eris\TestTrait;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Property 4: Exactly one entry per date, holding the last accepted submission.
 *
 * A sequence of one to six submissions is thrown at the same owner and the
 * same date, each one independently generated to be either an accepted answer
 * set or a rejected one (an out-of-range, non-integer, or missing mood
 * rating - Requirement 5.2, 5.5). Every submission goes through the real
 * pipeline this property is meant to protect: {@see DiaryInputValidator}
 * decides acceptance, {@see DiaryService::submitEntry()} only writes on
 * acceptance, and {@see DiaryEntryRepository::upsert()} performs the actual
 * `(owner_id, entry_date)`-keyed write (Requirements 5.3, 5.4).
 *
 * After every single submission - accepted or rejected - the entire
 * `diary_entries` table is snapshotted, so a rejection that writes anything at
 * all, or an acceptance that writes a second row instead of updating the
 * first, is caught immediately rather than only at the end of the sequence.
 * After the whole sequence, exactly one row exists for the date when at least
 * one submission was accepted (none otherwise), its content equals the last
 * *accepted* submission - not the last submission - and its id never changed
 * across however many accepted updates happened along the way (Requirement
 * 6.4's "one recommendation per entry" depends on that stable id).
 *
 * Retrieving the date's own CBT_Recommendation (the other half of Requirement
 * 8.2) is out of scope for this test: the AI_Feedback_Service and its
 * recommendation storage are not yet implemented, so there is nothing to
 * exercise there yet.
 *
 * Requirements: 5.3, 5.4, 6.4, 8.2.
 */
final class OneEntryPerDatePropertyTest extends TestCase
{
    use TestTrait;

    private const OWNER_ID = '01111111111111111111111111';
    private const ENTRY_DATE = '2025-06-15';
    private const CLOCK_START = '2025-06-15 08:00:00';

    protected function setUp(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_sqlite is not loaded.');
        }
    }

    // Feature: mental-health-diary, Property 4: Exactly one entry per date, holding the last accepted submission
    public function testExactlyOneEntryPerDateHoldingTheLastAcceptedSubmission(): void
    {
        $this->limitTo(100)
            ->forAll(self::submissionSequence())
            ->then(function (array $submissions): void {
                [$pdo, $repository, $service, $validator, $clock] = $this->newHarness();

                $owner = OwnerId::fromString(self::OWNER_ID);
                $date = LocalDate::fromString(self::ENTRY_DATE);

                $stableId = null;
                $lastAccepted = null;

                foreach ($submissions as $submission) {
                    $answers = self::toSubmittedAnswers($submission);
                    $validation = $validator->validate($answers);

                    $before = self::snapshot($pdo);
                    $result = $service->submitEntry($owner, $validation, $clock);
                    $after = self::snapshot($pdo);

                    if ($validation->isRejected()) {
                        self::assertTrue($result->isFailure(), 'a rejected submission must not be reported as saved');
                        self::assertSame(
                            $before,
                            $after,
                            'a rejected submission must leave the diary_entries table completely unchanged'
                        );

                        continue;
                    }

                    self::assertTrue($result->isOk(), 'an accepted submission must be reported as saved');

                    $entry = $result->value();
                    self::assertSame(
                        1,
                        self::countRowsForDate($pdo, self::OWNER_ID, self::ENTRY_DATE),
                        'exactly one row must exist for this owner and date right after an accepted submission'
                    );

                    if ($stableId === null) {
                        $stableId = $entry->id();
                    } else {
                        self::assertSame(
                            $stableId,
                            $entry->id(),
                            'a later accepted submission for the same date must update the existing row, not mint a new id'
                        );
                    }

                    $lastAccepted = $submission;
                    self::assertSubmissionStored($entry->input(), $submission);
                }

                $finalCount = self::countRowsForDate($pdo, self::OWNER_ID, self::ENTRY_DATE);
                $found = $repository->findByDate($owner, $date);

                if ($lastAccepted === null) {
                    self::assertSame(0, $finalCount, 'no submission was ever accepted, so no row should exist');
                    self::assertNull($found, 'no submission was ever accepted, so nothing should be findable');

                    return;
                }

                self::assertSame(1, $finalCount, 'exactly one row must exist for this owner and date');
                self::assertNotNull($found, 'the date with an accepted submission must be findable');
                self::assertSame($stableId, $found->id());
                self::assertSubmissionStored($found->input(), $lastAccepted);
            });
    }

    /**
     * @param array{mood: string, sleep: string, events: string, thoughts: string, emotions: string} $submission
     */
    private static function assertSubmissionStored(\Diary\Diary\DiaryEntryInput $input, array $submission): void
    {
        self::assertSame((int) $submission['mood'], $input->moodRating());
        self::assertSame(
            $submission['sleep'] === '' ? null : (int) $submission['sleep'],
            $input->sleepQuality()
        );
        self::assertSame(trim($submission['events']), $input->events());
        self::assertSame(trim($submission['thoughts']), $input->thoughts());
        self::assertSame(trim($submission['emotions']), $input->emotions());
    }

    /**
     * @param array{mood: string, sleep: string, events: string, thoughts: string, emotions: string} $submission
     */
    private static function toSubmittedAnswers(array $submission): SubmittedAnswers
    {
        return SubmittedAnswers::of([
            QuestionSet::DATE_FIELD => self::ENTRY_DATE,
            QuestionSet::MOOD_RATING => $submission['mood'],
            QuestionSet::SLEEP_QUALITY => $submission['sleep'],
            QuestionSet::EVENTS => $submission['events'],
            QuestionSet::THOUGHTS => $submission['thoughts'],
            QuestionSet::EMOTIONS => $submission['emotions'],
        ]);
    }

    /**
     * @return array{0: PDO, 1: DiaryEntryRepository, 2: DiaryService, 3: DiaryInputValidator, 4: FixedClock}
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

        $clock = FixedClock::at(self::CLOCK_START);
        $keyRing = new KeyRing($pdo, str_repeat("\x2a", KeyRing::KEY_LENGTH), $clock);
        $codec = new PayloadCodec(new Crypto($keyRing));

        $repository = new DiaryEntryRepository($pdo, $codec);
        $service = new DiaryService($repository);
        $validator = new DiaryInputValidator();

        return [$pdo, $repository, $service, $validator, $clock];
    }

    private static function countRowsForDate(PDO $pdo, string $ownerId, string $date): int
    {
        $statement = $pdo->prepare(
            'SELECT COUNT(*) AS c FROM diary_entries WHERE owner_id = :owner_id AND entry_date = :entry_date'
        );
        $statement->execute([':owner_id' => $ownerId, ':entry_date' => $date]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return (int) $row['c'];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function snapshot(PDO $pdo): array
    {
        $statement = $pdo->query('SELECT * FROM diary_entries ORDER BY id');
        $rows = $statement === false ? [] : $statement->fetchAll(PDO::FETCH_ASSOC);

        /** @var list<array<string, mixed>> $rows */
        return array_map(static fn (array $row): array => array_map(self::bytes(...), $row), $rows);
    }

    private static function bytes(mixed $value): mixed
    {
        if (is_resource($value)) {
            $contents = stream_get_contents($value);

            return $contents === false ? '' : $contents;
        }

        return $value;
    }

    /**
     * One to six submissions aimed at the same owner and date, each
     * independently accepted or rejected.
     */
    private static function submissionSequence(): \Eris\Generator
    {
        return Generator\bind(
            Generator\choose(1, 6),
            static fn (int $length): \Eris\Generator => Generator\vector($length, self::submission())
        );
    }

    /**
     * @return \Eris\Generator one submission: a mood rating that is sometimes
     *                         valid and sometimes not, a sleep quality that is
     *                         always well formed (so acceptance turns on the
     *                         mood rating alone), and three free-text answers
     */
    private static function submission(): \Eris\Generator
    {
        return Generator\map(
            static fn (array $parts): array => [
                'mood' => $parts[0],
                'sleep' => $parts[1],
                'events' => $parts[2],
                'thoughts' => $parts[3],
                'emotions' => $parts[4],
            ],
            Generator\tuple(
                self::moodRatingRaw(),
                self::sleepQualityRaw(),
                Generator\string(),
                Generator\string(),
                Generator\string()
            )
        );
    }

    /**
     * A mood rating as submitted: valid 1-10 most of the time, and otherwise
     * missing, out of the 1-10 range, or not a whole number (Requirement 5.2,
     * 5.5) - the three ways a submission is rejected in this test.
     */
    private static function moodRatingRaw(): \Eris\Generator
    {
        return Generator\oneOf(
            Generator\map(static fn (int $value): string => (string) $value, Generator\choose(1, 10)),
            Generator\constant(''),
            Generator\elements(['0', '11', '12', '-5', '100']),
            Generator\elements(['abc', '3.5', '7.0', 'ten', ' '])
        );
    }

    /**
     * Sleep quality as submitted: blank, or a valid 1-5 integer. Always well
     * formed, because this property isolates acceptance to the mood rating.
     */
    private static function sleepQualityRaw(): \Eris\Generator
    {
        return Generator\oneOf(
            Generator\constant(''),
            Generator\map(static fn (int $value): string => (string) $value, Generator\choose(1, 5))
        );
    }
}
