<?php

declare(strict_types=1);

namespace Diary\Tests\Unit\Storage;

use Diary\Storage\ConnectionFactory;
use Diary\Storage\Crypto;
use Diary\Storage\KeyRing;
use Diary\Storage\KeyRotationService;
use Diary\Storage\PayloadCodec;
use Diary\Support\FixedClock;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * KeyRotationService: the `/cron/keys` cron work (Requirement 4.2).
 *
 * Re-encrypts a bounded slice of rows across diary_entries, milestones and
 * cbt_recommendations onto the current active DEK, and retires a superseded
 * key only once nothing references it any more. Uses an in-memory SQLite
 * schema standing in for migrations 003-006, following the pattern in
 * tests/Unit/Storage/PurgeServiceTest.php and KeyRingTest.php.
 */
final class KeyRotationServiceTest extends TestCase
{
    private PDO $pdo;
    private FixedClock $clock;
    private KeyRing $keyRing;
    private PayloadCodec $codec;
    private KeyRotationService $service;

    protected function setUp(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_sqlite is not loaded.');
        }

        $this->pdo = ConnectionFactory::fromDsn('sqlite::memory:');
        $this->createSchema();

        $this->clock = FixedClock::at('2025-03-01 09:30:00');
        $masterKey = str_repeat("\x2a", KeyRing::KEY_LENGTH);
        $this->keyRing = new KeyRing($this->pdo, $masterKey, $this->clock);
        $this->codec = new PayloadCodec(new Crypto($this->keyRing));
        $this->service = new KeyRotationService($this->pdo, $this->keyRing, $this->codec);
    }

    private function createSchema(): void
    {
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
                id                 TEXT NOT NULL PRIMARY KEY,
                owner_id           TEXT NOT NULL,
                entry_date         TEXT NOT NULL,
                key_id             TEXT NOT NULL,
                nonce              BLOB NOT NULL,
                payload_ciphertext BLOB NOT NULL,
                created_at         TEXT NOT NULL,
                updated_at         TEXT NOT NULL
            )'
        );

        $this->pdo->exec(
            'CREATE TABLE milestones (
                id                 TEXT NOT NULL PRIMARY KEY,
                owner_id           TEXT NOT NULL,
                milestone_date     TEXT NOT NULL,
                key_id             TEXT NOT NULL,
                nonce              BLOB NOT NULL,
                payload_ciphertext BLOB NOT NULL,
                created_at         TEXT NOT NULL,
                updated_at         TEXT NOT NULL
            )'
        );

        $this->pdo->exec(
            'CREATE TABLE cbt_recommendations (
                id                 TEXT NOT NULL PRIMARY KEY,
                entry_id           TEXT NOT NULL,
                status             TEXT NOT NULL,
                key_id             TEXT     NULL,
                nonce              BLOB     NULL,
                payload_ciphertext BLOB     NULL,
                provider           TEXT     NULL,
                model              TEXT     NULL,
                attempt_count      INTEGER  NOT NULL DEFAULT 0,
                generated_at       TEXT     NULL
            )'
        );
    }

    private function insertEntry(string $id, string $keyId, array $payload = ['mood_rating' => 5]): void
    {
        $now = $this->clock->now()->format('Y-m-d H:i:s');

        // Encrypt with whichever key is active right now, then force the row's
        // key_id column to the id under test - simulating a row left over from
        // before a rotation, without needing the ring to still consider that
        // key active.
        $row = $this->codec->encodeRow(\Diary\Storage\PayloadShape::DiaryEntry, $id, [
            'mood_rating' => $payload['mood_rating'] ?? 5,
        ]);

        $statement = $this->pdo->prepare(
            'INSERT INTO diary_entries (id, owner_id, entry_date, key_id, nonce, payload_ciphertext, created_at, updated_at)
             VALUES (:id, \'owner1\', :date, :key_id, :nonce, :ct, :now, :now)'
        );
        $statement->bindValue(':id', $id);
        $statement->bindValue(':date', '2025-0' . (1 + (int) substr($id, -1) % 8) . '-01');
        $statement->bindValue(':key_id', $keyId);
        $statement->bindValue(':nonce', $row['nonce'], PDO::PARAM_LOB);
        $statement->bindValue(':ct', $row['payload_ciphertext'], PDO::PARAM_LOB);
        $statement->bindValue(':now', $now);
        $statement->execute();
    }

    /**
     * Encrypt a diary_entries row for `$id` under `$keyId` specifically (rather
     * than whatever the ring currently considers active), so a row can be set
     * up as genuinely readable under an old, now-inactive key.
     */
    private function insertEntryUnderKey(string $id, string $keyId): void
    {
        $dek = $this->keyRing->keyFor($keyId);
        $nonce = random_bytes(12);
        $tag = '';
        $json = json_encode(['mood_rating' => 5, 'sleep_quality' => null, 'events' => '', 'thoughts' => '', 'emotions' => '', 'schema_version' => 1]);
        $ciphertext = openssl_encrypt($json, Crypto::CIPHER, $dek, OPENSSL_RAW_DATA, $nonce, $tag, 'diary_entries' . "\0" . $id, 16);

        $now = $this->clock->now()->format('Y-m-d H:i:s');
        $statement = $this->pdo->prepare(
            'INSERT INTO diary_entries (id, owner_id, entry_date, key_id, nonce, payload_ciphertext, created_at, updated_at)
             VALUES (:id, \'owner1\', :date, :key_id, :nonce, :ct, :now, :now)'
        );
        $statement->bindValue(':id', $id);
        $statement->bindValue(':date', '2025-0' . (1 + (int) substr($id, -1) % 8) . '-01');
        $statement->bindValue(':key_id', $keyId);
        $statement->bindValue(':nonce', $nonce, PDO::PARAM_LOB);
        $statement->bindValue(':ct', $ciphertext . $tag, PDO::PARAM_LOB);
        $statement->bindValue(':now', $now);
        $statement->execute();
    }

    private function keyIdOf(string $table, string $id): string
    {
        $statement = $this->pdo->prepare("SELECT key_id FROM {$table} WHERE id = :id");
        $statement->execute([':id' => $id]);

        return (string) $statement->fetchColumn();
    }

    public function testReEncryptsARowOnAnOldKeyOntoTheActiveKey(): void
    {
        $oldKey = $this->keyRing->createKey();
        $this->insertEntryUnderKey('e1', $oldKey);

        $this->clock->advanceDays(1);
        $activeKey = $this->keyRing->createKey();
        $this->keyRing->forget();

        $report = $this->service->runSlice(100);

        self::assertSame(1, $report->reEncrypted);
        self::assertSame(0, $report->failed);
        self::assertSame($activeKey, $this->keyIdOf('diary_entries', 'e1'));

        // The payload still reads back correctly after re-encryption.
        $row = $this->pdo->query('SELECT * FROM diary_entries WHERE id = \'e1\'')->fetch(PDO::FETCH_ASSOC);
        $decoded = $this->codec->decode(\Diary\Storage\PayloadShape::DiaryEntry, 'e1', $row);
        self::assertSame(5, $decoded['mood_rating']);
    }

    public function testRetiresAnOldKeyOnlyOnceNoRowReferencesItAnyMore(): void
    {
        $oldKey = $this->keyRing->createKey();
        $this->insertEntryUnderKey('e1', $oldKey);

        $this->clock->advanceDays(1);
        $this->keyRing->createKey();
        $this->keyRing->forget();

        $report = $this->service->runSlice(100);

        self::assertSame(1, $report->retiredKeys);

        $retiredAt = $this->pdo->query("SELECT retired_at FROM encryption_keys WHERE id = '{$oldKey}'")->fetchColumn();
        self::assertNotNull($retiredAt);
    }

    public function testDoesNotRetireAnOldKeyStillReferencedByAnotherTable(): void
    {
        $oldKey = $this->keyRing->createKey();
        $this->insertEntryUnderKey('e1', $oldKey);

        // A milestone still on the old key, left alone this run because the
        // limit only covers diary_entries.
        $now = $this->clock->now()->format('Y-m-d H:i:s');
        $dek = $this->keyRing->keyFor($oldKey);
        $nonce = random_bytes(12);
        $tag = '';
        $json = json_encode(['description' => 'x', 'category' => 'other', 'schema_version' => 1]);
        $ciphertext = openssl_encrypt($json, Crypto::CIPHER, $dek, OPENSSL_RAW_DATA, $nonce, $tag, 'milestones' . "\0" . 'm1', 16);
        $statement = $this->pdo->prepare(
            'INSERT INTO milestones (id, owner_id, milestone_date, key_id, nonce, payload_ciphertext, created_at, updated_at)
             VALUES (\'m1\', \'owner1\', \'2025-03-01\', :key_id, :nonce, :ct, :now, :now)'
        );
        $statement->bindValue(':key_id', $oldKey);
        $statement->bindValue(':nonce', $nonce, PDO::PARAM_LOB);
        $statement->bindValue(':ct', $ciphertext . $tag, PDO::PARAM_LOB);
        $statement->bindValue(':now', $now);
        $statement->execute();

        $this->clock->advanceDays(1);
        $this->keyRing->createKey();
        $this->keyRing->forget();

        // Re-encrypt only the diary entry (limit 1 across the first shape),
        // leaving the milestone on the old key.
        $report = $this->service->runSlice(1);

        self::assertSame(1, $report->reEncrypted);
        self::assertSame(0, $report->retiredKeys, 'still referenced by the milestone row');

        $retiredAt = $this->pdo->query("SELECT retired_at FROM encryption_keys WHERE id = '{$oldKey}'")->fetchColumn();
        self::assertNull($retiredAt);
    }

    public function testIsIdempotentWhenRunTwiceInARow(): void
    {
        $oldKey = $this->keyRing->createKey();
        $this->insertEntryUnderKey('e1', $oldKey);

        $this->clock->advanceDays(1);
        $activeKey = $this->keyRing->createKey();
        $this->keyRing->forget();

        $first = $this->service->runSlice(100);
        self::assertSame(1, $first->reEncrypted);

        $second = $this->service->runSlice(100);

        self::assertSame(0, $second->reEncrypted, 'the row is already on the active key');
        self::assertSame($activeKey, $this->keyIdOf('diary_entries', 'e1'));
    }

    public function testIsBoundedByTheGivenLimit(): void
    {
        $oldKey = $this->keyRing->createKey();
        $this->insertEntryUnderKey('e1', $oldKey);
        $this->insertEntryUnderKey('e2', $oldKey);
        $this->insertEntryUnderKey('e3', $oldKey);

        $this->clock->advanceDays(1);
        $this->keyRing->createKey();
        $this->keyRing->forget();

        $report = $this->service->runSlice(2);

        self::assertSame(2, $report->reEncrypted);

        $count = (int) $this->pdo->query("SELECT COUNT(*) FROM diary_entries WHERE key_id = '{$oldKey}'")->fetchColumn();
        self::assertSame(1, $count);
    }
}
