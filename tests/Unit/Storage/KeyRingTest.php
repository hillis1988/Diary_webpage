<?php

declare(strict_types=1);

namespace Diary\Tests\Unit\Storage;

use Diary\Storage\ConnectionFactory;
use Diary\Storage\Crypto;
use Diary\Storage\CryptoException;
use Diary\Storage\KeyRing;
use Diary\Support\FixedClock;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Key ring behaviour: active key selection, retired keys staying readable, and
 * refusal to hand back a key that does not unwrap (Requirements 4.1, 4.2).
 *
 * Runs against an in-memory database with a schema shaped like
 * migrations/003_create_encryption_keys.sql, so it needs no server.
 */
final class KeyRingTest extends TestCase
{
    private PDO $pdo;
    private FixedClock $clock;
    private string $masterKey;

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
        $this->masterKey = str_repeat("\x2a", KeyRing::KEY_LENGTH);
    }

    private function keyRing(?string $masterKey = null): KeyRing
    {
        return new KeyRing($this->pdo, $masterKey ?? $this->masterKey, $this->clock);
    }

    public function testMasterKeyMustBeThirtyTwoBytes(): void
    {
        $this->expectException(CryptoException::class);

        $this->keyRing(str_repeat("\x2a", 31));
    }

    public function testFromConfigDecodesTheBase64MasterKey(): void
    {
        $ring = KeyRing::fromConfig(
            $this->pdo,
            ['encryption' => ['master_key_base64' => base64_encode($this->masterKey)]],
            $this->clock
        );

        $keyId = $ring->createKey();

        // The same raw key must unwrap what the base64 form wrapped.
        self::assertSame($ring->keyFor($keyId), $this->keyRing()->keyFor($keyId));
    }

    public function testFromConfigRejectsAnEmptyMasterKey(): void
    {
        $this->expectException(CryptoException::class);

        KeyRing::fromConfig($this->pdo, ['encryption' => ['master_key_base64' => '']], $this->clock);
    }

    public function testActiveKeyIdFailsWhenTheRingIsEmpty(): void
    {
        $this->expectException(CryptoException::class);

        $this->keyRing()->activeKeyId();
    }

    public function testEnsureActiveKeyProvisionsTheFirstKey(): void
    {
        $ring = $this->keyRing();

        $keyId = $ring->ensureActiveKey();

        self::assertSame($keyId, $ring->activeKeyId());
        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM encryption_keys')->fetchColumn());
        self::assertSame(KeyRing::KEY_LENGTH, strlen($ring->keyFor($keyId)));
    }

    public function testEachCreatedKeyIsDistinctAndTheNewestNonRetiredOneIsActive(): void
    {
        $ring = $this->keyRing();

        $first = $ring->createKey();
        $this->clock->advanceDays(1);
        $second = $ring->createKey();

        self::assertNotSame($first, $second);
        self::assertNotSame($ring->keyFor($first), $ring->keyFor($second));

        // A fresh ring reads the table rather than its own cache.
        self::assertSame($second, $this->keyRing()->activeKeyId());
    }

    public function testRetiringTheActiveKeyFallsBackToTheOlderKeyButKeepsBothReadable(): void
    {
        $ring = $this->keyRing();
        $first = $ring->createKey();
        $this->clock->advanceDays(1);
        $second = $ring->createKey();
        $secondMaterial = $ring->keyFor($second);

        $ring->retire($second);

        self::assertSame($first, $ring->activeKeyId());
        self::assertSame($secondMaterial, $this->keyRing()->keyFor($second), 'a retired key stays decryptable');
    }

    public function testUnknownKeyIdIsRefused(): void
    {
        $this->expectException(CryptoException::class);

        $this->keyRing()->keyFor('01JQZZZZZZZZZZZZZZZZZZZZZZ');
    }

    public function testADifferentMasterKeyCannotUnwrapTheDek(): void
    {
        $keyId = $this->keyRing()->createKey();

        $this->expectException(CryptoException::class);

        $this->keyRing(str_repeat("\x2b", KeyRing::KEY_LENGTH))->keyFor($keyId);
    }

    public function testAWrappedKeyIsBoundToItsOwnRow(): void
    {
        $ring = $this->keyRing();
        $first = $ring->createKey();
        $this->clock->advanceDays(1);
        $second = $ring->createKey();

        $row = $this->pdo->query(
            sprintf("SELECT wrapped_dek, wrap_nonce FROM encryption_keys WHERE id = '%s'", $first)
        )->fetch(PDO::FETCH_ASSOC);

        // Move the first row's wrapped key onto the second row.
        $move = $this->pdo->prepare(
            'UPDATE encryption_keys SET wrapped_dek = :dek, wrap_nonce = :nonce WHERE id = :id'
        );
        $move->bindValue(':dek', $row['wrapped_dek'], PDO::PARAM_LOB);
        $move->bindValue(':nonce', $row['wrap_nonce'], PDO::PARAM_LOB);
        $move->bindValue(':id', $second);
        $move->execute();

        $this->expectException(CryptoException::class);

        $this->keyRing()->keyFor($second);
    }

    public function testDekMaterialIsNotDerivableFromTheStoredRow(): void
    {
        $ring = $this->keyRing();
        $keyId = $ring->createKey();
        $dek = $ring->keyFor($keyId);

        $stored = (string) $this->pdo->query('SELECT wrapped_dek FROM encryption_keys')->fetchColumn();

        self::assertStringNotContainsString($dek, $stored);
        self::assertStringNotContainsString($this->masterKey, $stored);
    }

    public function testCipherIsAes256Gcm(): void
    {
        self::assertSame('aes-256-gcm', Crypto::CIPHER);
        self::assertContains(Crypto::CIPHER, openssl_get_cipher_methods());
    }
}
