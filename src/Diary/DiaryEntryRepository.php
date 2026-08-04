<?php

declare(strict_types=1);

namespace Diary\Diary;

use Diary\Access\OwnerId;
use Diary\Storage\PayloadCodec;
use Diary\Storage\PayloadShape;
use Diary\Storage\SqlTimestamp;
use Diary\Storage\StorageException;
use Diary\Support\Clock;
use Diary\Support\DateRange;
use Diary\Support\LocalDate;
use Diary\Support\Ulid;
use Diary\Support\YearMonth;
use PDO;
use Throwable;

/**
 * Owner-scoped, encrypted storage for diary entries (Requirements 4.4, 5.3, 5.4).
 *
 * Every method takes an {@see OwnerId} resolved by Access_Control_Service and
 * binds it as a SQL parameter; no method here can be called without a scope,
 * and owner scoping is never string interpolation.
 *
 * ## The upsert, and why the id needs care
 *
 * `upsert()` is keyed on the `(owner_id, entry_date)` unique index, so a second
 * submission for a date updates the same row rather than creating a duplicate
 * (Requirement 5.4). That matters beyond just row counts: `cbt_recommendations`
 * links to an entry by its id (a later task), so the id must stay the same
 * across every update to a given date - only the first submission for a date
 * may mint a fresh one.
 *
 * The subtlety is that {@see PayloadCodec} binds its ciphertext's authenticated
 * data to the *record id*. Encrypting before knowing which id the row will
 * actually have - a fresh one on insert, the existing one on update - would
 * either produce a ciphertext bound to an id nothing ever stores (on update) or
 * require re-encrypting after the fact. So the write proceeds in this order,
 * all inside one transaction:
 *
 *   1. Look up whether a row already exists for `(owner_id, entry_date)`, using
 *      a locking read (`SELECT ... FOR UPDATE` on MariaDB) so a concurrent
 *      submission for the same date cannot slip in between the lookup and the
 *      write - the lock is held for the rest of the transaction.
 *   2. Decide the id: the existing row's id when found, a freshly generated
 *      ULID otherwise.
 *   3. Encrypt the payload under that id.
 *   4. Issue one genuine upsert statement - `INSERT ... ON DUPLICATE KEY UPDATE`
 *      on MariaDB, `INSERT ... ON CONFLICT ... DO UPDATE` on SQLite in tests -
 *      naming the id explicitly. The statement never rewrites the `id` column
 *      of an existing row, so even in the vanishingly unlikely case that the
 *      locking read raced with another writer, the row keeps whichever id was
 *      already committed.
 *
 * This is a real upsert statement, not a SELECT-then-branch into a separate
 * INSERT or UPDATE call; the locking read exists only to pick the correct id
 * for the encryption step that has to happen before the write.
 */
final class DiaryEntryRepository
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly PayloadCodec $codec,
    ) {
    }

    /**
     * Encrypted upsert keyed on `(owner_id, entry_date)` inside a transaction.
     */
    public function upsert(OwnerId $owner, DiaryEntryInput $input, Clock $clock): DiaryEntry
    {
        $ownerId = $owner->toString();
        $entryDate = $input->date()->toIso();

        $startedTransaction = !$this->pdo->inTransaction();

        if ($startedTransaction) {
            $this->pdo->beginTransaction();
        }

        try {
            $existing = $this->lockExisting($ownerId, $entryDate);

            $now = $clock->now();
            $id = $existing !== null ? (string) $existing['id'] : Ulid::generate($clock);
            $createdAt = $existing !== null
                ? SqlTimestamp::parse($existing['created_at']) ?? $now
                : $now;

            $row = $this->codec->encodeRow(PayloadShape::DiaryEntry, $id, $input->toPayload());

            $statement = $this->pdo->prepare($this->upsertSql());
            $statement->bindValue(':id', $id);
            $statement->bindValue(':owner_id', $ownerId);
            $statement->bindValue(':entry_date', $entryDate);
            $statement->bindValue(':key_id', $row['key_id']);
            $statement->bindValue(':nonce', $row['nonce'], PDO::PARAM_LOB);
            $statement->bindValue(':payload_ciphertext', $row['payload_ciphertext'], PDO::PARAM_LOB);
            $statement->bindValue(':created_at', SqlTimestamp::format($createdAt));
            $statement->bindValue(':updated_at', SqlTimestamp::format($now));
            $statement->execute();

            if ($startedTransaction) {
                $this->pdo->commit();
            }
        } catch (Throwable $exception) {
            if ($startedTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw new StorageException('Could not save the diary entry.', 0, $exception);
        }

        return DiaryEntry::of($id, $owner, $input, $createdAt, $now);
    }

    public function findByDate(OwnerId $owner, LocalDate $date): ?DiaryEntry
    {
        $statement = $this->pdo->prepare(
            'SELECT id, owner_id, entry_date, key_id, nonce, payload_ciphertext, created_at, updated_at
             FROM diary_entries
             WHERE owner_id = :owner_id AND entry_date = :entry_date'
        );
        $statement->bindValue(':owner_id', $owner->toString());
        $statement->bindValue(':entry_date', $date->toIso());
        $statement->execute();

        $row = $statement->fetch(PDO::FETCH_ASSOC);

        if (!is_array($row)) {
            return null;
        }

        return $this->hydrate($owner, $row);
    }

    /**
     * @return list<DiaryEntry> ordered by date, ascending
     */
    public function findInRange(OwnerId $owner, DateRange $range): array
    {
        if ($range->isInverted()) {
            return [];
        }

        $statement = $this->pdo->prepare(
            'SELECT id, owner_id, entry_date, key_id, nonce, payload_ciphertext, created_at, updated_at
             FROM diary_entries
             WHERE owner_id = :owner_id AND entry_date BETWEEN :start_date AND :end_date
             ORDER BY entry_date ASC'
        );
        $statement->bindValue(':owner_id', $owner->toString());
        $statement->bindValue(':start_date', $range->start()->toIso());
        $statement->bindValue(':end_date', $range->end()->toIso());
        $statement->execute();

        $entries = [];

        /** @var array<string, mixed> $row */
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $entries[] = $this->hydrate($owner, $row);
        }

        return $entries;
    }

    /**
     * @return list<LocalDate> the dates within the month that carry an entry
     */
    public function datesWithEntries(OwnerId $owner, YearMonth $month): array
    {
        $range = $month->toDateRange();

        $statement = $this->pdo->prepare(
            'SELECT entry_date FROM diary_entries
             WHERE owner_id = :owner_id AND entry_date BETWEEN :start_date AND :end_date
             ORDER BY entry_date ASC'
        );
        $statement->bindValue(':owner_id', $owner->toString());
        $statement->bindValue(':start_date', $range->start()->toIso());
        $statement->bindValue(':end_date', $range->end()->toIso());
        $statement->execute();

        $dates = [];

        /** @var array<string, mixed> $row */
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $dates[] = LocalDate::fromString((string) $row['entry_date']);
        }

        return $dates;
    }

    /**
     * @return array{id: string, created_at: mixed}|null
     */
    private function lockExisting(string $ownerId, string $entryDate): ?array
    {
        $statement = $this->pdo->prepare($this->lockingSelectSql());
        $statement->bindValue(':owner_id', $ownerId);
        $statement->bindValue(':entry_date', $entryDate);
        $statement->execute();

        $row = $statement->fetch(PDO::FETCH_ASSOC);

        if (!is_array($row)) {
            return null;
        }

        /** @var array{id: string, created_at: mixed} $row */
        return $row;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(OwnerId $owner, array $row): DiaryEntry
    {
        $id = (string) $row['id'];
        $payload = $this->codec->decode(PayloadShape::DiaryEntry, $id, $row);

        $input = DiaryEntryInput::of(
            date: LocalDate::fromString((string) $row['entry_date']),
            moodRating: (int) $payload['mood_rating'],
            sleepQuality: $payload['sleep_quality'] === null ? null : (int) $payload['sleep_quality'],
            events: (string) $payload['events'],
            thoughts: (string) $payload['thoughts'],
            emotions: (string) $payload['emotions'],
        );

        $createdAt = SqlTimestamp::parse($row['created_at']);
        $updatedAt = SqlTimestamp::parse($row['updated_at']);

        return DiaryEntry::of(
            $id,
            $owner,
            $input,
            $createdAt ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
            $updatedAt ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
        );
    }

    private function lockingSelectSql(): string
    {
        $base = 'SELECT id, created_at FROM diary_entries WHERE owner_id = :owner_id AND entry_date = :entry_date';

        return $this->isMysql() ? $base . ' FOR UPDATE' : $base;
    }

    /**
     * A genuine upsert statement keyed on the `(owner_id, entry_date)` unique
     * index. The `id` column is written on insert only; `ON DUPLICATE KEY
     * UPDATE` / `ON CONFLICT ... DO UPDATE` never touch it, so an existing
     * row's id is never overwritten.
     */
    private function upsertSql(): string
    {
        $columns = '(id, owner_id, entry_date, key_id, nonce, payload_ciphertext, created_at, updated_at)';
        $values = 'VALUES (:id, :owner_id, :entry_date, :key_id, :nonce, :payload_ciphertext, :created_at, :updated_at)';

        if ($this->isMysql()) {
            return "INSERT INTO diary_entries $columns $values
                ON DUPLICATE KEY UPDATE
                    key_id = VALUES(key_id),
                    nonce = VALUES(nonce),
                    payload_ciphertext = VALUES(payload_ciphertext),
                    updated_at = VALUES(updated_at)";
        }

        // SQLite: exercised by the unit test suite as a stand-in for MariaDB.
        return "INSERT INTO diary_entries $columns $values
            ON CONFLICT(owner_id, entry_date) DO UPDATE SET
                key_id = excluded.key_id,
                nonce = excluded.nonce,
                payload_ciphertext = excluded.payload_ciphertext,
                updated_at = excluded.updated_at";
    }

    private function isMysql(): bool
    {
        return $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
    }
}
