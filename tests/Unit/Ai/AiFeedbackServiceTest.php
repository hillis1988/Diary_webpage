<?php

declare(strict_types=1);

namespace Diary\Tests\Unit\Ai;

use Diary\Ai\AiConfig;
use Diary\Ai\AiFeedbackService;
use Diary\Ai\CbtRecommendation;
use Diary\Ai\CbtRecommendationRepository;
use Diary\Ai\FeedbackStatus;
use Diary\Ai\ProviderError;
use Diary\Diary\DiaryEntry;
use Diary\Diary\DiaryEntryInput;
use Diary\Access\OwnerId;
use Diary\Storage\ConnectionFactory;
use Diary\Storage\Crypto;
use Diary\Storage\KeyRing;
use Diary\Storage\PayloadCodec;
use Diary\Support\FixedClock;
use Diary\Support\LocalDate;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * AiFeedbackService: shape validation, encrypted storage, failure isolation
 * and the retry control (Requirements 6.1-6.5).
 *
 * Uses an in-memory SQLite schema standing in for migrations 003 and 005,
 * following the pattern in tests/Unit/Diary/DiaryEntryRepositoryTest.php.
 */
final class AiFeedbackServiceTest extends TestCase
{
    private PDO $pdo;
    private CbtRecommendationRepository $repository;
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
            'CREATE TABLE cbt_recommendations (
                id                 CHAR(26)   NOT NULL PRIMARY KEY,
                entry_id           CHAR(26)   NOT NULL,
                status             TEXT       NOT NULL,
                key_id             CHAR(26)   NULL,
                nonce              BLOB       NULL,
                payload_ciphertext BLOB       NULL,
                provider           TEXT       NULL,
                model              TEXT       NULL,
                attempt_count      INTEGER    NOT NULL DEFAULT 0,
                generated_at       DATETIME   NULL,
                UNIQUE (entry_id)
            )'
        );

        $this->clock = FixedClock::at('2025-03-01 09:30:00');

        $keyRing = new KeyRing($this->pdo, str_repeat("\x2a", KeyRing::KEY_LENGTH), $this->clock);
        $codec = new PayloadCodec(new Crypto($keyRing));

        $this->repository = new CbtRecommendationRepository($this->pdo, $codec);
    }

    private function config(): AiConfig
    {
        return AiConfig::fromConfig(['ai' => [
            'enabled' => true,
            'provider' => 'example-provider',
            'endpoint' => 'https://api.example.com/v1/chat/completions',
            'api_key' => 'secret-key',
            'model' => 'example-model',
            'timeout_seconds' => 20,
            'retries' => 1,
        ]]);
    }

    private function entry(string $id = '01ARZ3NDEKTSV4RRFFQ69G5FA1'): DiaryEntry
    {
        $input = DiaryEntryInput::of(
            date: LocalDate::fromString('2025-03-01'),
            moodRating: 7,
            sleepQuality: 3,
            events: 'Went for a walk.',
            thoughts: 'Felt calmer.',
            emotions: 'Content',
        );

        $owner = OwnerId::fromString('0' . str_repeat('1', 25));

        return DiaryEntry::of($id, $owner, $input, $this->clock->now(), $this->clock->now());
    }

    private function service(FakeFeedbackProvider $provider): AiFeedbackService
    {
        return new AiFeedbackService($provider, $this->repository, $this->config());
    }

    public function testSuccessfulGenerationStoresEncryptedWithCorrectMetadata(): void
    {
        $provider = new FakeFeedbackProvider();
        $provider->queue(new CbtRecommendation('You went for a walk.', 'Try a short walk tomorrow too.'));
        $entry = $this->entry();

        $outcome = $this->service($provider)->generateForEntry($entry, $this->clock);

        self::assertTrue($outcome->isGenerated());
        self::assertSame('You went for a walk.', $outcome->recommendation()->positiveFocus());
        self::assertSame('Try a short walk tomorrow too.', $outcome->recommendation()->suggestedChange());

        $record = $this->repository->findByEntryId($entry->id());
        self::assertNotNull($record);
        self::assertTrue($record->isGenerated());
        self::assertSame('example-provider', $record->provider());
        self::assertSame('example-model', $record->model());
        self::assertSame(1, $record->attemptCount());
        self::assertNotNull($record->generatedAt());

        $row = $this->pdo->query('SELECT payload_ciphertext FROM cbt_recommendations')->fetch(PDO::FETCH_ASSOC);
        self::assertStringNotContainsString('walk', (string) $row['payload_ciphertext']);
    }

    public function testEmptyPositiveFocusIsRejectedAsFailure(): void
    {
        $provider = new FakeFeedbackProvider();
        $provider->queue(new CbtRecommendation('   ', 'Try a short walk tomorrow too.'));
        $entry = $this->entry();

        $outcome = $this->service($provider)->generateForEntry($entry, $this->clock);

        self::assertTrue($outcome->isUnavailable());

        $record = $this->repository->findByEntryId($entry->id());
        self::assertNotNull($record);
        self::assertFalse($record->isGenerated());
        self::assertSame(1, $record->attemptCount());
        $this->assertNoCiphertextStored();
    }

    public function testEmptySuggestedChangeIsRejectedAsFailure(): void
    {
        $provider = new FakeFeedbackProvider();
        $provider->queue(new CbtRecommendation('You went for a walk.', ''));
        $entry = $this->entry();

        $outcome = $this->service($provider)->generateForEntry($entry, $this->clock);

        self::assertTrue($outcome->isUnavailable());

        $record = $this->repository->findByEntryId($entry->id());
        self::assertNotNull($record);
        self::assertFalse($record->isGenerated());
        $this->assertNoCiphertextStored();
    }

    /**
     * A malformed-JSON or otherwise unparsable provider response surfaces
     * from {@see \Diary\Ai\HttpsFeedbackProvider} as a {@see ProviderError};
     * this is the same failure path the service applies to shape-validation
     * rejections, so it belongs alongside them: no partial write, ever.
     */
    public function testProviderThrowingProviderErrorResultsInFailedStatusAndUnavailableOutcome(): void
    {
        $provider = new FakeFeedbackProvider();
        $provider->queueFailure(new ProviderError('timed out'));
        $entry = $this->entry();

        $outcome = $this->service($provider)->generateForEntry($entry, $this->clock);

        self::assertTrue($outcome->isUnavailable());

        $record = $this->repository->findByEntryId($entry->id());
        self::assertNotNull($record);
        self::assertFalse($record->isGenerated());
        self::assertSame(1, $record->attemptCount());
        $this->assertNoCiphertextStored();
    }

    private function assertNoCiphertextStored(): void
    {
        $row = $this->pdo->query('SELECT key_id, nonce, payload_ciphertext FROM cbt_recommendations')->fetch(PDO::FETCH_ASSOC);
        self::assertNull($row['key_id']);
        self::assertNull($row['nonce']);
        self::assertNull($row['payload_ciphertext']);
    }

    public function testRepeatedFailuresKeepStatusFailedOnTheSameRowWithIncrementingAttemptCount(): void
    {
        $provider = new FakeFeedbackProvider();
        $provider->queueFailure(new ProviderError('timed out'));
        $provider->queueFailure(new ProviderError('still timed out'));
        $entry = $this->entry();
        $service = $this->service($provider);

        $first = $service->generateForEntry($entry, $this->clock);
        self::assertTrue($first->isUnavailable());
        self::assertSame(1, $this->countRows());

        $second = $service->retry($entry, $this->clock);
        self::assertTrue($second->isUnavailable());
        self::assertSame(1, $this->countRows());

        $record = $this->repository->findByEntryId($entry->id());
        self::assertNotNull($record);
        self::assertSame(FeedbackStatus::Failed, $record->status());
        self::assertFalse($record->isGenerated());
        self::assertSame(2, $record->attemptCount());
        self::assertNull($record->generatedAt());
    }

    public function testRetryReinvokesTheProviderAndUpdatesTheSameRowRatherThanDuplicating(): void
    {
        $provider = new FakeFeedbackProvider();
        $provider->queueFailure(new ProviderError('timed out'));
        $entry = $this->entry();

        $first = $this->service($provider)->generateForEntry($entry, $this->clock);
        self::assertTrue($first->isUnavailable());
        self::assertSame(1, $this->countRows());

        $provider->queue(new CbtRecommendation('You went for a walk.', 'Try a short walk tomorrow too.'));
        $second = $this->service($provider)->retry($entry, $this->clock);

        self::assertTrue($second->isGenerated());
        self::assertSame(1, $this->countRows());
        self::assertSame(2, $provider->callCount());

        $record = $this->repository->findByEntryId($entry->id());
        self::assertNotNull($record);
        self::assertTrue($record->isGenerated());
        self::assertSame(2, $record->attemptCount());
    }

    public function testOneRecommendationPerEntryIsRespectedAcrossRetries(): void
    {
        $provider = new FakeFeedbackProvider();
        $provider->queueFailure(new ProviderError('timed out'));
        $provider->queueFailure(new ProviderError('timed out again'));
        $provider->queue(new CbtRecommendation('Focus.', 'Change.'));
        $entry = $this->entry();
        $service = $this->service($provider);

        $service->generateForEntry($entry, $this->clock);
        $service->retry($entry, $this->clock);
        $service->retry($entry, $this->clock);

        self::assertSame(1, $this->countRows());

        $record = $this->repository->findByEntryId($entry->id());
        self::assertNotNull($record);
        self::assertTrue($record->isGenerated());
        self::assertSame(3, $record->attemptCount());
    }

    private function countRows(): int
    {
        $result = $this->pdo->query('SELECT COUNT(*) AS c FROM cbt_recommendations')->fetch(PDO::FETCH_ASSOC);

        return (int) $result['c'];
    }
}
