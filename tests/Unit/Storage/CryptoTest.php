<?php

declare(strict_types=1);

namespace Diary\Tests\Unit\Storage;

use Diary\Storage\ConnectionFactory;
use Diary\Storage\Crypto;
use Diary\Storage\CryptoException;
use Diary\Storage\Envelope;
use Diary\Storage\KeyRing;
use Diary\Support\FixedClock;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Round-tripping, nonce freshness, record binding and tamper detection for the
 * payload encryption used by every sensitive table (Requirements 4.1, 4.2).
 *
 * The exhaustive version of these checks is the property test in task 3.2;
 * these are the worked examples.
 */
final class CryptoTest extends TestCase
{
    private const TABLE = 'diary_entries';
    private const RECORD_ID = '01JQ0000000000000000000001';

    private PDO $pdo;
    private FixedClock $clock;
    private KeyRing $keyRing;
    private Crypto $crypto;

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

        $this->clock = FixedClock::at('2025-03-01 09:30:00');
        $this->keyRing = new KeyRing($this->pdo, str_repeat("\x2a", KeyRing::KEY_LENGTH), $this->clock);
        $this->crypto = new Crypto($this->keyRing);
    }

    private function payload(): string
    {
        return json_encode([
            'mood_rating' => 7,
            'sleep_quality' => 3,
            'events' => 'Walked to the coast 🌊',
            'thoughts' => "I coped better than I expected",
            'emotions' => 'relieved',
            'schema_version' => 1,
        ], JSON_THROW_ON_ERROR);
    }

    public function testPayloadRoundTripsThroughTheStoredColumns(): void
    {
        $plaintext = $this->payload();

        $envelope = $this->crypto->encryptFor(self::TABLE, self::RECORD_ID, $plaintext);
        $row = $envelope->toRow();

        self::assertSame(Envelope::NONCE_LENGTH, strlen($row['nonce']));
        self::assertSame($this->keyRing->activeKeyId(), $row['key_id']);

        $decrypted = $this->crypto->decryptFor(self::TABLE, self::RECORD_ID, Envelope::fromRow($row));

        self::assertSame($plaintext, $decrypted);
    }

    public function testEmptyPayloadRoundTrips(): void
    {
        $envelope = $this->crypto->encryptFor(self::TABLE, self::RECORD_ID, '');

        self::assertSame(Envelope::TAG_LENGTH, strlen($envelope->ciphertext));
        self::assertSame('', $this->crypto->decryptFor(self::TABLE, self::RECORD_ID, $envelope));
    }

    public function testCiphertextContainsNoPlaintext(): void
    {
        $envelope = $this->crypto->encryptFor(self::TABLE, self::RECORD_ID, $this->payload());

        self::assertStringNotContainsString('Walked to the coast', $envelope->ciphertext);
        self::assertStringNotContainsString('relieved', $envelope->ciphertext);
    }

    public function testEveryWriteDrawsAFreshNonceAndProducesDifferentCiphertext(): void
    {
        $plaintext = $this->payload();
        $nonces = [];
        $ciphertexts = [];

        for ($i = 0; $i < 25; $i++) {
            $envelope = $this->crypto->encryptFor(self::TABLE, self::RECORD_ID, $plaintext);
            $nonces[] = $envelope->nonce;
            $ciphertexts[] = $envelope->ciphertext;
        }

        self::assertCount(25, array_unique($nonces), 'a nonce is never reused for a write');
        self::assertCount(25, array_unique($ciphertexts), 'identical plaintext must not produce identical ciphertext');
    }

    public function testACiphertextMovedToAnotherRecordIdFailsToDecrypt(): void
    {
        $envelope = $this->crypto->encryptFor(self::TABLE, self::RECORD_ID, $this->payload());

        $this->expectException(CryptoException::class);

        $this->crypto->decryptFor(self::TABLE, '01JQ0000000000000000000002', $envelope);
    }

    public function testACiphertextMovedToAnotherTableFailsToDecrypt(): void
    {
        $envelope = $this->crypto->encryptFor(self::TABLE, self::RECORD_ID, $this->payload());

        $this->expectException(CryptoException::class);

        $this->crypto->decryptFor('milestones', self::RECORD_ID, $envelope);
    }

    public function testAModifiedCiphertextFailsToDecrypt(): void
    {
        $envelope = $this->crypto->encryptFor(self::TABLE, self::RECORD_ID, $this->payload());
        $bytes = $envelope->ciphertext;
        $bytes[0] = chr(ord($bytes[0]) ^ 0x01);

        $this->expectException(CryptoException::class);

        $this->crypto->decryptFor(
            self::TABLE,
            self::RECORD_ID,
            Envelope::of($envelope->keyId, $envelope->nonce, $bytes)
        );
    }

    public function testAModifiedNonceFailsToDecrypt(): void
    {
        $envelope = $this->crypto->encryptFor(self::TABLE, self::RECORD_ID, $this->payload());
        $nonce = $envelope->nonce;
        $nonce[11] = chr(ord($nonce[11]) ^ 0x01);

        $this->expectException(CryptoException::class);

        $this->crypto->decryptFor(
            self::TABLE,
            self::RECORD_ID,
            Envelope::of($envelope->keyId, $nonce, $envelope->ciphertext)
        );
    }

    public function testAModifiedAuthenticationTagFailsToDecrypt(): void
    {
        $envelope = $this->crypto->encryptFor(self::TABLE, self::RECORD_ID, $this->payload());
        $bytes = $envelope->ciphertext;
        $last = strlen($bytes) - 1;
        $bytes[$last] = chr(ord($bytes[$last]) ^ 0x01);

        $this->expectException(CryptoException::class);

        $this->crypto->decryptFor(
            self::TABLE,
            self::RECORD_ID,
            Envelope::of($envelope->keyId, $envelope->nonce, $bytes)
        );
    }

    public function testDataWrittenUnderARetiredKeyStaysReadable(): void
    {
        $plaintext = $this->payload();
        $envelope = $this->crypto->encryptFor(self::TABLE, self::RECORD_ID, $plaintext);
        $oldKeyId = $envelope->keyId;

        $this->clock->advanceDays(1);
        $this->keyRing->retire($oldKeyId);
        $newKeyId = $this->keyRing->ensureActiveKey();

        self::assertNotSame($oldKeyId, $newKeyId);

        // A cold ring, as a later request would see it.
        $crypto = new Crypto(new KeyRing($this->pdo, str_repeat("\x2a", KeyRing::KEY_LENGTH), $this->clock));

        self::assertSame($plaintext, $crypto->decryptFor(self::TABLE, self::RECORD_ID, $envelope));
        self::assertSame(
            $newKeyId,
            $crypto->encryptFor(self::TABLE, self::RECORD_ID, $plaintext)->keyId,
            'new writes use the active key, not the retired one'
        );
    }

    public function testAnEnvelopeSealedUnderADeletedKeyCannotBeRead(): void
    {
        $envelope = $this->crypto->encryptFor(self::TABLE, self::RECORD_ID, $this->payload());
        $this->pdo->exec('DELETE FROM encryption_keys');

        $this->expectException(CryptoException::class);

        (new Crypto(new KeyRing($this->pdo, str_repeat("\x2a", KeyRing::KEY_LENGTH), $this->clock)))
            ->decryptFor(self::TABLE, self::RECORD_ID, $envelope);
    }

    public function testAadRequiresBothTableAndRecordId(): void
    {
        self::assertSame("diary_entries\0" . self::RECORD_ID, Crypto::aad(self::TABLE, self::RECORD_ID));

        $this->expectException(CryptoException::class);

        Crypto::aad(self::TABLE, '');
    }

    public function testEnvelopeRejectsAMalformedNonceOrCiphertext(): void
    {
        $keyId = $this->keyRing->ensureActiveKey();

        try {
            Envelope::of($keyId, str_repeat("\x00", 11), str_repeat("\x00", Envelope::TAG_LENGTH));
            self::fail('an 11-byte nonce must be rejected');
        } catch (CryptoException) {
            // expected
        }

        $this->expectException(CryptoException::class);

        Envelope::of($keyId, str_repeat("\x00", Envelope::NONCE_LENGTH), str_repeat("\x00", 15));
    }
}
