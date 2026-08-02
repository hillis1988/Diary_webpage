<?php

declare(strict_types=1);

namespace Diary\Storage;

use PDO;
use Throwable;

/**
 * Key rotation cron work (Requirement 4.2): re-encrypt a bounded slice of
 * rows onto the current active DEK, then retire any superseded key that no
 * row references any more.
 *
 * `KeyRing::createKey()` (the first half of rotation) is invoked by an
 * operator when a rotation is wanted; this class is the second half, called
 * repeatedly by `/cron/keys` until every row referencing an old key has been
 * moved. Each call is a bounded, idempotent slice: a row already on the
 * active key is never selected again, and a row that fails to re-encrypt
 * (for example, a key that no longer unwraps) is simply left on its old key
 * for a later run rather than aborting the whole slice.
 *
 * Every sensitive table (`diary_entries`, `milestones`, `cbt_recommendations`)
 * carries its own `key_id` column, so "unreferenced" is checked across all
 * three before a key is retired.
 */
final class KeyRotationService
{
    /** The tables carrying a `key_id` column that rotation may need to move. */
    private const SHAPES = [PayloadShape::DiaryEntry, PayloadShape::Milestone, PayloadShape::CbtRecommendation];

    public function __construct(
        private readonly PDO $pdo,
        private readonly KeyRing $keyRing,
        private readonly PayloadCodec $codec,
    ) {
    }

    /**
     * Re-encrypt up to `$limit` rows (summed across the three tables) onto
     * the current active key, then retire any old key left unreferenced.
     */
    public function runSlice(int $limit): KeyRotationReport
    {
        $limit = max(0, $limit);

        if ($limit === 0) {
            return KeyRotationReport::empty();
        }

        $activeKeyId = $this->keyRing->ensureActiveKey();

        $reEncrypted = 0;
        $failed = 0;
        $remaining = $limit;

        foreach (self::SHAPES as $shape) {
            if ($remaining <= 0) {
                break;
            }

            [$done, $failedHere] = $this->reEncryptSlice($shape, $activeKeyId, $remaining);
            $reEncrypted += $done;
            $failed += $failedHere;
            $remaining -= $done + $failedHere;
        }

        $retiredKeys = $this->retireUnreferencedKeys($activeKeyId);

        return KeyRotationReport::of($reEncrypted, $failed, $retiredKeys);
    }

    /**
     * @return array{0: int, 1: int} [rows re-encrypted, rows left failed]
     */
    private function reEncryptSlice(PayloadShape $shape, string $activeKeyId, int $limit): array
    {
        $table = $shape->table();

        $select = $this->pdo->prepare(
            "SELECT id, key_id, nonce, payload_ciphertext FROM {$table}
             WHERE key_id IS NOT NULL AND key_id <> :active
             LIMIT :limit"
        );
        $select->bindValue(':active', $activeKeyId);
        $select->bindValue(':limit', $limit, PDO::PARAM_INT);
        $select->execute();

        /** @var list<array<string, mixed>> $rows */
        $rows = $select->fetchAll(PDO::FETCH_ASSOC);

        $done = 0;
        $failed = 0;

        foreach ($rows as $row) {
            $id = (string) $row['id'];

            try {
                // Decrypt with whichever key the row currently references
                // (KeyRing keeps a retired key readable), then re-encrypt with
                // the active one.
                $payload = $this->codec->decode($shape, $id, $row);
                $newRow = $this->codec->encodeRow($shape, $id, $payload);

                $update = $this->pdo->prepare(
                    "UPDATE {$table} SET key_id = :key_id, nonce = :nonce, payload_ciphertext = :ciphertext WHERE id = :id"
                );
                $update->bindValue(':key_id', $newRow['key_id']);
                $update->bindValue(':nonce', $newRow['nonce'], PDO::PARAM_LOB);
                $update->bindValue(':ciphertext', $newRow['payload_ciphertext'], PDO::PARAM_LOB);
                $update->bindValue(':id', $id);
                $update->execute();

                $done++;
            } catch (Throwable) {
                // Left on its old key for a later run. A decryption/authentication
                // failure here must not abort the rest of the slice, and it must
                // not touch the row - the row keeps whatever it could still read.
                $failed++;
            }
        }

        return [$done, $failed];
    }

    /**
     * Retire every unretired key, other than the active one, that no row in
     * any of the three sensitive tables references any more.
     */
    private function retireUnreferencedKeys(string $activeKeyId): int
    {
        /** @var array<string, true> $referenced */
        $referenced = [$activeKeyId => true];

        foreach (self::SHAPES as $shape) {
            $table = $shape->table();
            $statement = $this->pdo->query("SELECT DISTINCT key_id FROM {$table} WHERE key_id IS NOT NULL");

            if ($statement === false) {
                continue;
            }

            /** @var array<string, mixed> $row */
            foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $referenced[(string) $row['key_id']] = true;
            }
        }

        $candidates = $this->pdo->prepare(
            'SELECT id FROM ' . KeyRing::TABLE . ' WHERE retired_at IS NULL AND id <> :active'
        );
        $candidates->bindValue(':active', $activeKeyId);
        $candidates->execute();

        $retired = 0;

        /** @var array<string, mixed> $row */
        foreach ($candidates->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $keyId = (string) $row['id'];

            if (!isset($referenced[$keyId])) {
                $this->keyRing->retire($keyId);
                $retired++;
            }
        }

        return $retired;
    }
}
