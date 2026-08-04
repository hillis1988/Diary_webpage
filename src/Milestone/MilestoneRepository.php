<?php

declare(strict_types=1);

namespace Diary\Milestone;

use Diary\Access\OwnerId;
use Diary\Storage\PayloadCodec;
use Diary\Storage\PayloadShape;
use Diary\Storage\SqlTimestamp;
use Diary\Storage\StorageException;
use Diary\Support\Clock;
use Diary\Support\DateRange;
use Diary\Support\LocalDate;
use Diary\Support\Ulid;
use PDO;
use Throwable;

/**
 * Owner-scoped, encrypted storage for milestones (Requirements 4.1, 4.2, 10.1,
 * 10.2, 10.3, 10.5).
 *
 * Every method takes an {@see OwnerId} resolved by Access_Control_Service and
 * binds it as a SQL parameter; no method here can be called without a scope,
 * and owner scoping is never string interpolation. Unlike
 * {@see \Diary\Diary\DiaryEntryRepository}, milestones are not one-per-date: a
 * date may carry several milestones, and each record has its own lifecycle
 * (create, update, delete) rather than an upsert keyed on the date.
 *
 * `update()` and `delete()` scope their `WHERE` clause on `(id, owner_id)`
 * together, never `id` alone, so a mutation aimed at another owner's row
 * matches no rows rather than acting on it.
 */
final class MilestoneRepository
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly PayloadCodec $codec,
    ) {
    }

    public function create(OwnerId $owner, MilestoneInput $input, Clock $clock): Milestone
    {
        $id = Ulid::generate($clock);
        $now = $clock->now();

        try {
            $row = $this->codec->encodeRow(PayloadShape::Milestone, $id, $input->toPayload());

            $statement = $this->pdo->prepare(
                'INSERT INTO milestones
                    (id, owner_id, milestone_date, key_id, nonce, payload_ciphertext, created_at, updated_at)
                 VALUES
                    (:id, :owner_id, :milestone_date, :key_id, :nonce, :payload_ciphertext, :created_at, :updated_at)'
            );
            $statement->bindValue(':id', $id);
            $statement->bindValue(':owner_id', $owner->toString());
            $statement->bindValue(':milestone_date', $input->date()->toIso());
            $statement->bindValue(':key_id', $row['key_id']);
            $statement->bindValue(':nonce', $row['nonce'], PDO::PARAM_LOB);
            $statement->bindValue(':payload_ciphertext', $row['payload_ciphertext'], PDO::PARAM_LOB);
            $statement->bindValue(':created_at', SqlTimestamp::format($now));
            $statement->bindValue(':updated_at', SqlTimestamp::format($now));
            $statement->execute();
        } catch (Throwable $exception) {
            throw new StorageException('Could not save the milestone.', 0, $exception);
        }

        return Milestone::of(MilestoneId::fromString($id), $owner, $input, $now, $now);
    }

    /**
     * Applies the change to the stored milestone (Requirement 10.3), scoped to
     * the owner. Returns null when no milestone with this id belongs to this
     * owner, so a caller can distinguish "not found" from a storage fault.
     */
    public function update(OwnerId $owner, MilestoneId $id, MilestoneInput $input, Clock $clock): ?Milestone
    {
        $startedTransaction = !$this->pdo->inTransaction();

        if ($startedTransaction) {
            $this->pdo->beginTransaction();
        }

        try {
            $existing = $this->lockExisting($owner, $id);

            if ($existing === null) {
                if ($startedTransaction) {
                    $this->pdo->rollBack();
                }

                return null;
            }

            $now = $clock->now();
            $createdAt = SqlTimestamp::parse($existing['created_at']) ?? $now;

            $row = $this->codec->encodeRow(PayloadShape::Milestone, $id->toString(), $input->toPayload());

            $statement = $this->pdo->prepare(
                'UPDATE milestones
                 SET milestone_date = :milestone_date,
                     key_id = :key_id,
                     nonce = :nonce,
                     payload_ciphertext = :payload_ciphertext,
                     updated_at = :updated_at
                 WHERE id = :id AND owner_id = :owner_id'
            );
            $statement->bindValue(':milestone_date', $input->date()->toIso());
            $statement->bindValue(':key_id', $row['key_id']);
            $statement->bindValue(':nonce', $row['nonce'], PDO::PARAM_LOB);
            $statement->bindValue(':payload_ciphertext', $row['payload_ciphertext'], PDO::PARAM_LOB);
            $statement->bindValue(':updated_at', SqlTimestamp::format($now));
            $statement->bindValue(':id', $id->toString());
            $statement->bindValue(':owner_id', $owner->toString());
            $statement->execute();

            if ($startedTransaction) {
                $this->pdo->commit();
            }
        } catch (Throwable $exception) {
            if ($startedTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw new StorageException('Could not update the milestone.', 0, $exception);
        }

        return Milestone::of($id, $owner, $input, $createdAt, $now);
    }

    /**
     * Deletes the milestone, scoped to the owner. Returns false when no
     * milestone with this id belongs to this owner.
     */
    public function delete(OwnerId $owner, MilestoneId $id): bool
    {
        try {
            $statement = $this->pdo->prepare(
                'DELETE FROM milestones WHERE id = :id AND owner_id = :owner_id'
            );
            $statement->bindValue(':id', $id->toString());
            $statement->bindValue(':owner_id', $owner->toString());
            $statement->execute();
        } catch (Throwable $exception) {
            throw new StorageException('Could not delete the milestone.', 0, $exception);
        }

        return $statement->rowCount() > 0;
    }

    public function findById(OwnerId $owner, MilestoneId $id): ?Milestone
    {
        $statement = $this->pdo->prepare(
            'SELECT id, owner_id, milestone_date, key_id, nonce, payload_ciphertext, created_at, updated_at
             FROM milestones
             WHERE id = :id AND owner_id = :owner_id'
        );
        $statement->bindValue(':id', $id->toString());
        $statement->bindValue(':owner_id', $owner->toString());
        $statement->execute();

        $row = $statement->fetch(PDO::FETCH_ASSOC);

        if (!is_array($row)) {
            return null;
        }

        return $this->hydrate($owner, $row);
    }

    /**
     * Every milestone belonging to the owner whose date falls within the given
     * range, inclusive (Requirements 8.3, 9.3).
     *
     * @return list<Milestone> ordered by date, ascending
     */
    public function inRange(OwnerId $owner, DateRange $range): array
    {
        if ($range->isInverted()) {
            return [];
        }

        $statement = $this->pdo->prepare(
            'SELECT id, owner_id, milestone_date, key_id, nonce, payload_ciphertext, created_at, updated_at
             FROM milestones
             WHERE owner_id = :owner_id AND milestone_date BETWEEN :start_date AND :end_date
             ORDER BY milestone_date ASC'
        );
        $statement->bindValue(':owner_id', $owner->toString());
        $statement->bindValue(':start_date', $range->start()->toIso());
        $statement->bindValue(':end_date', $range->end()->toIso());
        $statement->execute();

        $milestones = [];

        /** @var array<string, mixed> $row */
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $milestones[] = $this->hydrate($owner, $row);
        }

        return $milestones;
    }

    /**
     * @return array{created_at: mixed}|null
     */
    private function lockExisting(OwnerId $owner, MilestoneId $id): ?array
    {
        $statement = $this->pdo->prepare($this->lockingSelectSql());
        $statement->bindValue(':id', $id->toString());
        $statement->bindValue(':owner_id', $owner->toString());
        $statement->execute();

        $row = $statement->fetch(PDO::FETCH_ASSOC);

        if (!is_array($row)) {
            return null;
        }

        /** @var array{created_at: mixed} $row */
        return $row;
    }

    private function lockingSelectSql(): string
    {
        $base = 'SELECT created_at FROM milestones WHERE id = :id AND owner_id = :owner_id';

        return $this->isMysql() ? $base . ' FOR UPDATE' : $base;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(OwnerId $owner, array $row): Milestone
    {
        $id = (string) $row['id'];
        $payload = $this->codec->decode(PayloadShape::Milestone, $id, $row);

        $input = MilestoneInput::of(
            date: LocalDate::fromString((string) $row['milestone_date']),
            description: (string) $payload['description'],
            category: MilestoneCategory::from((string) $payload['category']),
        );

        $createdAt = SqlTimestamp::parse($row['created_at']);
        $updatedAt = SqlTimestamp::parse($row['updated_at']);

        return Milestone::of(
            MilestoneId::fromString($id),
            $owner,
            $input,
            $createdAt ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
            $updatedAt ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
        );
    }

    private function isMysql(): bool
    {
        return $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
    }
}
