<?php

declare(strict_types=1);

namespace Diary\Tests\Property;

use Diary\Storage\ConnectionFactory;
use Diary\Storage\Crypto;
use Diary\Storage\CryptoException;
use Diary\Storage\Envelope;
use Diary\Storage\KeyRing;
use Diary\Support\FixedClock;
use Diary\Support\Ulid;
use Eris\Generator;
use Eris\TestTrait;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Property 11: Special category data is never stored in plaintext.
 *
 * The payload is written through the same path a repository uses (encrypt with
 * the record binding, bind the three columns, INSERT) into a real table, then
 * read back out of the database as raw bytes. Asserting against what the
 * database actually holds - rather than against the Envelope in memory - is the
 * point: a mapping slip that wrote the plaintext into the blob column would
 * still pass an in-memory check.
 *
 * Requirements: 4.1, 4.2.
 */
final class EncryptionAtRestPropertyTest extends TestCase
{
    use TestTrait;

    /**
     * Values shorter than this are not searched for in the ciphertext: a one or
     * two byte needle occurs in ~100 bytes of random data often enough to fail
     * by coincidence, which would say nothing about encryption. Every free-text
     * answer of real length, and the JSON document as a whole, is still checked.
     */
    private const MINIMUM_FRAGMENT_LENGTH = 4;

    private const OWNER_ID = '01JQ0000000000000000000OWN';

    private PDO $pdo;
    private FixedClock $clock;
    private KeyRing $keyRing;
    private Crypto $crypto;
    private int $rowsWritten = 0;

    protected function setUp(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_sqlite is not loaded.');
        }

        $this->pdo = ConnectionFactory::fromDsn('sqlite::memory:');
        $this->pdo->exec(
            'CREATE TABLE encryption_keys (
                id          CHAR(26) NOT NULL PRIMARY KEY,
                wrapped_dek BLOB     NOT NULL,
                wrap_nonce  BLOB     NOT NULL,
                created_at  DATETIME NOT NULL,
                retired_at  DATETIME NULL
            )'
        );
        $this->pdo->exec(
            'CREATE TABLE diary_entries (
                id                 CHAR(26) NOT NULL PRIMARY KEY,
                owner_id           CHAR(26) NOT NULL,
                entry_date         DATE     NOT NULL,
                key_id             CHAR(26) NOT NULL,
                nonce              BLOB     NOT NULL,
                payload_ciphertext BLOB     NOT NULL,
                created_at         DATETIME NOT NULL
            )'
        );
        $this->pdo->exec(
            'CREATE TABLE milestones (
                id                 CHAR(26) NOT NULL PRIMARY KEY,
                owner_id           CHAR(26) NOT NULL,
                milestone_date     DATE     NOT NULL,
                key_id             CHAR(26) NOT NULL,
                nonce              BLOB     NOT NULL,
                payload_ciphertext BLOB     NOT NULL,
                created_at         DATETIME NOT NULL
            )'
        );

        $this->clock = FixedClock::at('2025-03-01 09:30:00');
        $this->keyRing = new KeyRing($this->pdo, str_repeat("\x2a", KeyRing::KEY_LENGTH), $this->clock);
        $this->crypto = new Crypto($this->keyRing);
        $this->rowsWritten = 0;
    }

    // Feature: mental-health-diary, Property 11: Special category data is never stored in plaintext
    public function testSpecialCategoryDataIsNeverStoredInPlaintext(): void
    {
        $this->limitTo(100)
            ->forAll(
                self::diaryEntryPayload(),
                self::milestonePayload(),
                Generator\choose(0, 3)
            )
            ->then(function (array $entry, array $milestone, int $tampering): void {
                $this->assertStoredEncrypted('diary_entries', 'entry_date', $entry, $tampering);
                $this->assertStoredEncrypted('milestones', 'milestone_date', $milestone, $tampering);
            });
    }

    /**
     * One round trip: persist, read the raw row back, and check the four claims
     * Property 11 makes about it.
     *
     * @param array<string, mixed> $payload
     * @param int                  $tampering which part of the stored record to corrupt
     */
    private function assertStoredEncrypted(
        string $table,
        string $dateColumn,
        array $payload,
        int $tampering
    ): void {
        $id = Ulid::generate($this->clock);
        $plaintext = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

        $envelope = $this->crypto->encryptFor($table, $id, $plaintext);
        $this->insert($table, $dateColumn, $id, $envelope);
        $stored = $this->readRow($table, $id);

        // The cipher is AES-256-GCM: a 256-bit key, a 12-byte nonce and a
        // 16-byte authentication tag travelling with the ciphertext.
        self::assertSame('aes-256-gcm', Crypto::CIPHER);
        self::assertSame(Crypto::CIPHER, $envelope->cipher());
        self::assertSame(32, strlen($this->keyRing->keyFor($stored['key_id'])));
        self::assertSame(Envelope::NONCE_LENGTH, strlen($stored['nonce']));
        self::assertGreaterThan(Envelope::TAG_LENGTH, strlen($stored['payload_ciphertext']));

        // Nothing readable anywhere in the stored representation.
        $storedBytes = $stored['key_id'] . $stored['nonce'] . $stored['payload_ciphertext'];

        foreach (self::plaintextFragments($payload, $plaintext) as $fragment) {
            self::assertStringNotContainsString(
                $fragment,
                $storedBytes,
                sprintf('plaintext "%s" must not appear in the stored bytes of %s', $fragment, $table)
            );
        }

        // Reading it back yields exactly the original values.
        $decrypted = $this->crypto->decryptFor($table, $id, Envelope::fromRow($stored));

        self::assertSame($plaintext, $decrypted);
        self::assertEquals($payload, json_decode($decrypted, true, 512, JSON_THROW_ON_ERROR));

        // Any change to the ciphertext, the nonce or the record binding fails
        // rather than handing back altered data.
        $this->assertTamperingIsRejected($table, $id, Envelope::fromRow($stored), $tampering);
    }

    private function assertTamperingIsRejected(
        string $table,
        string $id,
        Envelope $envelope,
        int $tampering
    ): void {
        [$tamperedTable, $tamperedId, $tampered] = match ($tampering) {
            0 => [$table, $id, Envelope::of($envelope->keyId, $envelope->nonce, self::flipBit($envelope->ciphertext, 0))],
            1 => [
                $table,
                $id,
                Envelope::of(
                    $envelope->keyId,
                    $envelope->nonce,
                    self::flipBit($envelope->ciphertext, strlen($envelope->ciphertext) - 1)
                ),
            ],
            2 => [$table, $id, Envelope::of($envelope->keyId, self::flipBit($envelope->nonce, 0), $envelope->ciphertext)],
            default => [$table === 'milestones' ? 'diary_entries' : 'milestones', Ulid::generate($this->clock), $envelope],
        };

        try {
            $result = $this->crypto->decryptFor($tamperedTable, $tamperedId, $tampered);
            self::fail(sprintf(
                'a tampered record (case %d) decrypted to %s instead of failing',
                $tampering,
                var_export($result, true)
            ));
        } catch (CryptoException) {
            // Expected: authentication fails, no altered plaintext is returned.
        }
    }

    private function insert(string $table, string $dateColumn, string $id, Envelope $envelope): void
    {
        // A distinct date per row, so the real unique index on (owner, date) is
        // never the thing that fails during a run of a hundred iterations.
        $date = $this->clock->now()->modify(sprintf('+%d days', $this->rowsWritten++))->format('Y-m-d');
        $row = $envelope->toRow();

        $statement = $this->pdo->prepare(sprintf(
            'INSERT INTO %s (id, owner_id, %s, key_id, nonce, payload_ciphertext, created_at)
             VALUES (:id, :owner_id, :date, :key_id, :nonce, :payload_ciphertext, :created_at)',
            $table,
            $dateColumn
        ));
        $statement->bindValue(':id', $id);
        $statement->bindValue(':owner_id', self::OWNER_ID);
        $statement->bindValue(':date', $date);
        $statement->bindValue(':key_id', $row['key_id']);
        $statement->bindValue(':nonce', $row['nonce'], PDO::PARAM_LOB);
        $statement->bindValue(':payload_ciphertext', $row['payload_ciphertext'], PDO::PARAM_LOB);
        $statement->bindValue(':created_at', $this->clock->now()->format('Y-m-d H:i:s'));
        $statement->execute();
    }

    /**
     * @return array{key_id: string, nonce: string, payload_ciphertext: string}
     */
    private function readRow(string $table, string $id): array
    {
        $statement = $this->pdo->prepare(sprintf(
            'SELECT key_id, nonce, payload_ciphertext FROM %s WHERE id = :id',
            $table
        ));
        $statement->execute([':id' => $id]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        self::assertIsArray($row, 'the encrypted row must be readable back from the database');

        return [
            'key_id' => (string) $row['key_id'],
            'nonce' => self::bytes($row['nonce']),
            'payload_ciphertext' => self::bytes($row['payload_ciphertext']),
        ];
    }

    /**
     * The plaintext values a leak would reveal: the whole JSON document plus
     * every value long enough to be searched for on its own.
     *
     * @param array<string, mixed> $payload
     *
     * @return list<string>
     */
    private static function plaintextFragments(array $payload, string $plaintext): array
    {
        $fragments = [$plaintext];

        foreach ($payload as $value) {
            if (is_string($value) && strlen($value) >= self::MINIMUM_FRAGMENT_LENGTH) {
                $fragments[] = $value;
            }
        }

        return array_values(array_unique($fragments));
    }

    /**
     * Diary entry payload as designed: mood rating 1-10 required, sleep quality
     * 1-5 optional, three free-text answers.
     */
    private static function diaryEntryPayload(): \Eris\Generator
    {
        return Generator\associative([
            'mood_rating' => Generator\choose(1, 10),
            'sleep_quality' => Generator\oneOf(Generator\constant(null), Generator\choose(1, 5)),
            'events' => Generator\string(),
            'thoughts' => Generator\string(),
            'emotions' => Generator\string(),
            'food_meals' => Generator\constant([]),
            'schema_version' => Generator\constant(2),
        ]);
    }

    /**
     * Milestone payload as designed: free-text description plus a category from
     * the closed set. The category is part of the encrypted payload because
     * "medication" is health information on its own.
     */
    private static function milestonePayload(): \Eris\Generator
    {
        return Generator\associative([
            'description' => Generator\string(),
            'category' => Generator\elements(['medication', 'relationship', 'lifestyle', 'other']),
            'schema_version' => Generator\constant(2),
        ]);
    }

    private static function flipBit(string $bytes, int $offset): string
    {
        $bytes[$offset] = chr(ord($bytes[$offset]) ^ 0x01);

        return $bytes;
    }

    private static function bytes(mixed $value): string
    {
        if (is_resource($value)) {
            $contents = stream_get_contents($value);
            $value = $contents === false ? '' : $contents;
        }

        self::assertIsString($value, 'an encrypted column must hold bytes');

        return $value;
    }
}
