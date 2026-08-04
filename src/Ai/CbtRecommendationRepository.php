<?php

declare(strict_types=1);

namespace Diary\Ai;

use DateTimeImmutable;
use Diary\Storage\PayloadCodec;
use Diary\Storage\PayloadShape;
use Diary\Storage\SqlTimestamp;
use Diary\Storage\StorageException;
use Diary\Support\Clock;
use Diary\Support\Ulid;
use PDO;
use Throwable;

/**
 * Encrypted storage for `cbt_recommendations`, at most one row per entry
 * (Requirement 6.4).
 *
 * Follows the same shape as {@see \Diary\Diary\DiaryEntryRepository}: an
 * upsert keyed on a unique index - here `entry_id` rather than
 * `(owner_id, entry_date)` - inside a transaction, deciding the row's id and
 * `attempt_count` from a locking read before encrypting, so the ciphertext
 * (when there is one) is bound to the id the row will actually keep.
 *
 * A failed attempt writes no ciphertext at all: `key_id`, `nonce` and
 * `payload_ciphertext` stay `NULL`, only the bookkeeping columns advance
 * (Requirement 6.5). A later retry that succeeds then fills those columns in
 * on the same row.
 */
final class CbtRecommendationRepository
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly PayloadCodec $codec,
    ) {
    }

    /**
     * Record an accepted recommendation: encrypted, `status = 'generated'`.
     */
    public function recordSuccess(
        string $entryId,
        CbtRecommendation $recommendation,
        string $provider,
        string $model,
        DateTimeImmutable $generatedAt,
        Clock $clock,
    ): CbtRecommendationRecord {
        $payload = [
            'positive_focus' => $recommendation->positiveFocus(),
            'suggested_change' => $recommendation->suggestedChange(),
        ];

        [$id, $attemptCount] = $this->beginAttempt($entryId, $clock);

        try {
            $row = $this->codec->encodeRow(PayloadShape::CbtRecommendation, $id, $payload);

            $statement = $this->pdo->prepare($this->upsertSql());
            $statement->bindValue(':id', $id);
            $statement->bindValue(':entry_id', $entryId);
            $statement->bindValue(':status', FeedbackStatus::Generated->value);
            $statement->bindValue(':key_id', $row['key_id']);
            $statement->bindValue(':nonce', $row['nonce'], PDO::PARAM_LOB);
            $statement->bindValue(':payload_ciphertext', $row['payload_ciphertext'], PDO::PARAM_LOB);
            $statement->bindValue(':provider', $provider);
            $statement->bindValue(':model', $model);
            $statement->bindValue(':attempt_count', $attemptCount, PDO::PARAM_INT);
            $statement->bindValue(':generated_at', SqlTimestamp::format($generatedAt));
            $statement->execute();

            $this->commit();
        } catch (Throwable $exception) {
            $this->rollBack();

            throw new StorageException('Could not save the CBT recommendation.', 0, $exception);
        }

        return CbtRecommendationRecord::generated($id, $entryId, $recommendation, $provider, $model, $attemptCount, $generatedAt);
    }

    /**
     * Record a failed attempt: no ciphertext, `status = 'failed'`.
     */
    public function recordFailure(
        string $entryId,
        string $provider,
        string $model,
        Clock $clock,
    ): CbtRecommendationRecord {
        [$id, $attemptCount] = $this->beginAttempt($entryId, $clock);

        try {
            $statement = $this->pdo->prepare($this->upsertSql());
            $statement->bindValue(':id', $id);
            $statement->bindValue(':entry_id', $entryId);
            $statement->bindValue(':status', FeedbackStatus::Failed->value);
            $statement->bindValue(':key_id', null, PDO::PARAM_NULL);
            $statement->bindValue(':nonce', null, PDO::PARAM_NULL);
            $statement->bindValue(':payload_ciphertext', null, PDO::PARAM_NULL);
            $statement->bindValue(':provider', $provider);
            $statement->bindValue(':model', $model);
            $statement->bindValue(':attempt_count', $attemptCount, PDO::PARAM_INT);
            $statement->bindValue(':generated_at', null, PDO::PARAM_NULL);
            $statement->execute();

            $this->commit();
        } catch (Throwable $exception) {
            $this->rollBack();

            throw new StorageException('Could not save the CBT recommendation.', 0, $exception);
        }

        return CbtRecommendationRecord::failed($id, $entryId, $provider, $model, $attemptCount);
    }

    public function findByEntryId(string $entryId): ?CbtRecommendationRecord
    {
        $statement = $this->pdo->prepare(
            'SELECT id, entry_id, status, key_id, nonce, payload_ciphertext, provider, model, attempt_count, generated_at
             FROM cbt_recommendations
             WHERE entry_id = :entry_id'
        );
        $statement->bindValue(':entry_id', $entryId);
        $statement->execute();

        $row = $statement->fetch(PDO::FETCH_ASSOC);

        if (!is_array($row)) {
            return null;
        }

        return $this->hydrate($row);
    }

    /**
     * Lock any existing row for this entry and return the id and attempt
     * count the write in progress should use: the existing row's id (or a
     * fresh ULID) and its attempt count plus one.
     *
     * Starts the transaction the caller's write completes with
     * {@see self::commit()} or {@see self::rollBack()}.
     *
     * @return array{0: string, 1: int}
     */
    private function beginAttempt(string $entryId, Clock $clock): array
    {
        if (!$this->pdo->inTransaction()) {
            $this->pdo->beginTransaction();
        }

        $existing = $this->lockExisting($entryId);

        $id = $existing !== null ? (string) $existing['id'] : Ulid::generate($clock);
        $attemptCount = $existing !== null ? ((int) $existing['attempt_count']) + 1 : 1;

        return [$id, $attemptCount];
    }

    private function commit(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->commit();
        }
    }

    private function rollBack(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    /**
     * @return array{id: string, attempt_count: int}|null
     */
    private function lockExisting(string $entryId): ?array
    {
        $statement = $this->pdo->prepare($this->lockingSelectSql());
        $statement->bindValue(':entry_id', $entryId);
        $statement->execute();

        $row = $statement->fetch(PDO::FETCH_ASSOC);

        if (!is_array($row)) {
            return null;
        }

        /** @var array{id: string, attempt_count: int} $row */
        return $row;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): CbtRecommendationRecord
    {
        $id = (string) $row['id'];
        $entryId = (string) $row['entry_id'];
        $status = FeedbackStatus::from((string) $row['status']);
        $provider = (string) ($row['provider'] ?? '');
        $model = (string) ($row['model'] ?? '');
        $attemptCount = (int) $row['attempt_count'];

        if ($status === FeedbackStatus::Failed) {
            return CbtRecommendationRecord::failed($id, $entryId, $provider, $model, $attemptCount);
        }

        $payload = $this->codec->decode(PayloadShape::CbtRecommendation, $id, $row);
        $recommendation = new CbtRecommendation((string) $payload['positive_focus'], (string) $payload['suggested_change']);
        $generatedAt = SqlTimestamp::parse($row['generated_at']) ?? new DateTimeImmutable('now', new \DateTimeZone('UTC'));

        return CbtRecommendationRecord::generated($id, $entryId, $recommendation, $provider, $model, $attemptCount, $generatedAt);
    }

    private function lockingSelectSql(): string
    {
        $base = 'SELECT id, attempt_count FROM cbt_recommendations WHERE entry_id = :entry_id';

        return $this->isMysql() ? $base . ' FOR UPDATE' : $base;
    }

    /**
     * A genuine upsert statement keyed on the unique `entry_id` index. The
     * `id` column is written on insert only; the `ON DUPLICATE KEY UPDATE` /
     * `ON CONFLICT ... DO UPDATE` clauses never touch it, so a retry keeps
     * the same row rather than adding a second one (Requirement 6.4).
     */
    private function upsertSql(): string
    {
        $columns = '(id, entry_id, status, key_id, nonce, payload_ciphertext, provider, model, attempt_count, generated_at)';
        $values = 'VALUES (:id, :entry_id, :status, :key_id, :nonce, :payload_ciphertext, :provider, :model, :attempt_count, :generated_at)';

        if ($this->isMysql()) {
            return "INSERT INTO cbt_recommendations $columns $values
                ON DUPLICATE KEY UPDATE
                    status = VALUES(status),
                    key_id = VALUES(key_id),
                    nonce = VALUES(nonce),
                    payload_ciphertext = VALUES(payload_ciphertext),
                    provider = VALUES(provider),
                    model = VALUES(model),
                    attempt_count = VALUES(attempt_count),
                    generated_at = VALUES(generated_at)";
        }

        // SQLite: exercised by the unit test suite as a stand-in for MariaDB.
        return "INSERT INTO cbt_recommendations $columns $values
            ON CONFLICT(entry_id) DO UPDATE SET
                status = excluded.status,
                key_id = excluded.key_id,
                nonce = excluded.nonce,
                payload_ciphertext = excluded.payload_ciphertext,
                provider = excluded.provider,
                model = excluded.model,
                attempt_count = excluded.attempt_count,
                generated_at = excluded.generated_at";
    }

    private function isMysql(): bool
    {
        return $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
    }
}
