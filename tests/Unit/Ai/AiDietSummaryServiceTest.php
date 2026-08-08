<?php

declare(strict_types=1);

namespace Diary\Tests\Unit\Ai;

use Diary\Access\AccessControlService;
use Diary\Access\OwnerId;
use Diary\Ai\AiDietSummaryService;
use Diary\Ai\DietSummary;
use Diary\Ai\DietSummaryInput;
use Diary\Ai\DietSummaryOutcome;
use Diary\Ai\DietSummaryProvider;
use Diary\Ai\ProviderError;
use Diary\Auth\SecurityContext;
use Diary\Auth\Session;
use Diary\Auth\SessionId;
use Diary\Auth\UserId;
use Diary\Auth\UserRole;
use Diary\Diary\DiaryEntryInput;
use Diary\Diary\DiaryEntryRepository;
use Diary\Diary\DiaryService;
use Diary\Diary\DiaryValidation;
use Diary\Diary\FoodDiary;
use Diary\Diary\FoodMeal;
use Diary\Storage\ConnectionFactory;
use Diary\Storage\Crypto;
use Diary\Storage\KeyRing;
use Diary\Storage\PayloadCodec;
use Diary\Support\DateRange;
use Diary\Support\FixedClock;
use Diary\Support\LocalDate;
use Diary\Support\Ulid;
use PDO;
use PHPUnit\Framework\TestCase;

final class AiDietSummaryServiceTest extends TestCase
{
    private PDO $pdo;
    private FixedClock $clock;
    private AccessControlService $access;
    private DiaryService $diaryService;

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

        $this->clock = FixedClock::at('2025-06-10 12:00:00');
        $codec = new PayloadCodec(new Crypto(new KeyRing(
            $this->pdo,
            str_repeat("\x2a", KeyRing::KEY_LENGTH),
            $this->clock
        )));
        $this->diaryService = new DiaryService(new DiaryEntryRepository($this->pdo, $codec));
        $this->access = new AccessControlService($this->clock);
    }

    public function testSkipsWhenNoFoodMealsInRange(): void
    {
        $owner = $this->ownerId();
        $this->saveEntry($owner, '2025-06-01', FoodDiary::empty());

        $service = new AiDietSummaryService($this->diaryService, new class implements DietSummaryProvider {
            public function generate(DietSummaryInput $input): DietSummary
            {
                throw new ProviderError('should not be called');
            }
        });

        $outcome = $service->analyse(
            $owner,
            DateRange::of(LocalDate::fromString('2025-06-01'), LocalDate::fromString('2025-06-30'))
        );

        self::assertTrue($outcome->isSkipped());
    }

    public function testReturnsNotesWhenFoodExists(): void
    {
        $owner = $this->ownerId();
        $this->saveEntry(
            $owner,
            '2025-06-01',
            FoodDiary::of([FoodMeal::of(FoodMeal::TYPE_BREAKFAST, 'Oats')])
        );

        $service = new AiDietSummaryService($this->diaryService, new class implements DietSummaryProvider {
            public function generate(DietSummaryInput $input): DietSummary
            {
                return new DietSummary('overview', 'patterns', 'mood links', 'suggestion');
            }
        });

        $outcome = $service->analyse(
            $owner,
            DateRange::of(LocalDate::fromString('2025-06-01'), LocalDate::fromString('2025-06-30'))
        );

        self::assertTrue($outcome->isNotes());
        self::assertSame('overview', $outcome->summaryValue()->overview());
    }

    public function testProviderFailureIsUnavailableNotFatal(): void
    {
        $owner = $this->ownerId();
        $this->saveEntry(
            $owner,
            '2025-06-01',
            FoodDiary::of([FoodMeal::of(FoodMeal::TYPE_LUNCH, 'Soup')])
        );

        $service = new AiDietSummaryService($this->diaryService, new class implements DietSummaryProvider {
            public function generate(DietSummaryInput $input): DietSummary
            {
                throw new ProviderError('down');
            }
        });

        $outcome = $service->analyse(
            $owner,
            DateRange::of(LocalDate::fromString('2025-06-01'), LocalDate::fromString('2025-06-30'))
        );

        self::assertTrue($outcome->isUnavailable());
        self::assertSame(DietSummaryOutcome::UNAVAILABLE_MESSAGE, $outcome->reason());
    }

    private function ownerId(): OwnerId
    {
        $userId = UserId::fromString(Ulid::generate($this->clock));
        $session = new Session(
            id: SessionId::fromString(str_repeat('a', 64)),
            userId: $userId,
            contextRole: UserRole::Owner,
            dataOwnerId: $userId,
            createdAt: $this->clock->now(),
            lastActivityAt: $this->clock->now(),
        );

        return $this->access->resolveDataOwner(SecurityContext::forSession($session));
    }

    private function saveEntry(OwnerId $owner, string $date, FoodDiary $foodDiary): void
    {
        $input = DiaryEntryInput::of(
            date: LocalDate::fromString($date),
            moodRating: 6,
            sleepQuality: 3,
            events: 'day',
            foodDiary: $foodDiary,
        );

        $this->diaryService->submitEntry(
            $owner,
            DiaryValidation::accepted($input, $input->toSubmittedAnswers()),
            $this->clock,
        );
    }
}
