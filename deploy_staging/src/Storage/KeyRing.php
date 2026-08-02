<?php

declare(strict_types=1);

namespace Diary\Storage;

use Diary\Support\Clock;
use Diary\Support\SystemClock;
use Diary\Support\Ulid;
use PDO;

/**
 * The set of data encryption keys (DEKs) available to the application.
 *
 * Envelope encryption: the master key (KEK) lives in config/config.php outside
 * the document root and never encrypts a diary entry directly. It only unwraps
 * the DEKs stored in `encryption_keys`, and those DEKs encrypt the payloads.
 * That indirection is what makes rotation possible: adding a new DEK and
 * re-encrypting rows in slices never needs the plaintext of the master key to
 * change (Requirements 4.1, 4.2).
 *
 * Rules this class enforces:
 *
 *   - the active key for new writes is the newest row with `retired_at IS NULL`;
 *   - a retired key stays usable for decryption, so rows referencing it keep
 *     reading correctly until the rotation cron has moved them;
 *   - each wrapped DEK is bound to its own row id as additional authenticated
 *     data, so a wrapped key cannot be swapped between rows;
 *   - unwrapped DEKs are cached per instance only. Nothing writes them to disk,
 *     a log, or a session.
 */
final class KeyRing
{
    /** DEK and KEK length in bytes: AES-256. */
    public const KEY_LENGTH = 32;

    /** The table holding wrapped DEKs; also the AAD prefix for wrapping. */
    public const TABLE = 'encryption_keys';

    private readonly string $masterKey;

    private readonly Clock $clock;

    /**
     * Unwrapped DEKs by key id.
     *
     * @var array<string, string>
     */
    private array $keys = [];

    private ?string $activeKeyId = null;

    /**
     * @param string $masterKey 32 raw bytes
     */
    public function __construct(
        private readonly PDO $pdo,
        string $masterKey,
        ?Clock $clock = null
    ) {
        if (strlen($masterKey) !== self::KEY_LENGTH) {
            throw new CryptoException(sprintf(
                'The master encryption key must be exactly %d raw bytes.',
                self::KEY_LENGTH
            ));
        }

        $this->masterKey = $masterKey;
        $this->clock = $clock ?? new SystemClock();
    }

    /**
     * Build from the application configuration array (or just its
     * 'encryption' section), decoding `master_key_base64`.
     *
     * @param array<string, mixed> $config
     */
    public static function fromConfig(PDO $pdo, array $config, ?Clock $clock = null): self
    {
        $section = array_key_exists('encryption', $config) ? $config['encryption'] : $config;

        if (!is_array($section) || !array_key_exists('master_key_base64', $section)) {
            throw new CryptoException('Configuration is missing "encryption.master_key_base64".');
        }

        $encoded = $section['master_key_base64'];

        if (!is_string($encoded) || $encoded === '') {
            throw new CryptoException(
                'Configuration "encryption.master_key_base64" must hold a base64-encoded 32-byte key.'
            );
        }

        $decoded = base64_decode($encoded, true);

        if ($decoded === false) {
            throw new CryptoException('Configuration "encryption.master_key_base64" is not valid base64.');
        }

        return new self($pdo, $decoded, $clock);
    }

    /**
     * The key id new writes must use.
     *
     * @throws CryptoException when the ring holds no usable key
     */
    public function activeKeyId(): string
    {
        if ($this->activeKeyId !== null) {
            return $this->activeKeyId;
        }

        $id = $this->newestUnretiredKeyId();

        if ($id === null) {
            throw new CryptoException(
                'No active data encryption key is available; create one before writing encrypted data.'
            );
        }

        // Unwrap eagerly: an active key that cannot be unwrapped (wrong master
        // key, corrupted row) must fail before a caller starts a write.
        $this->keyFor($id);

        return $this->activeKeyId = $id;
    }

    /**
     * The active key id, provisioning the first DEK when the ring is empty.
     *
     * Called on the encryption path so a fresh deployment does not need a
     * separate bootstrap step; once a key exists this is a single indexed read.
     */
    public function ensureActiveKey(): string
    {
        if ($this->activeKeyId !== null) {
            return $this->activeKeyId;
        }

        $id = $this->newestUnretiredKeyId();

        if ($id === null) {
            return $this->createKey();
        }

        $this->keyFor($id);

        return $this->activeKeyId = $id;
    }

    /**
     * Generate a fresh DEK, store it wrapped, and make it the active key.
     *
     * This is also the first half of rotation: the rotation cron creates a key,
     * re-encrypts rows onto it in slices, then retires the superseded key.
     *
     * @return string the new key id
     */
    public function createKey(): string
    {
        $id = Ulid::generate($this->clock);
        $dek = random_bytes(self::KEY_LENGTH);
        $nonce = random_bytes(Envelope::NONCE_LENGTH);
        $tag = '';

        $wrapped = openssl_encrypt(
            $dek,
            Crypto::CIPHER,
            $this->masterKey,
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
            Crypto::aad(self::TABLE, $id),
            Envelope::TAG_LENGTH
        );

        if ($wrapped === false || strlen($tag) !== Envelope::TAG_LENGTH) {
            throw new CryptoException('Could not wrap a new data encryption key.');
        }

        $statement = $this->pdo->prepare(sprintf(
            'INSERT INTO %s (id, wrapped_dek, wrap_nonce, created_at) VALUES (:id, :wrapped_dek, :wrap_nonce, :created_at)',
            self::TABLE
        ));
        $statement->bindValue(':id', $id);
        $statement->bindValue(':wrapped_dek', $wrapped . $tag, PDO::PARAM_LOB);
        $statement->bindValue(':wrap_nonce', $nonce, PDO::PARAM_LOB);
        $statement->bindValue(':created_at', $this->clock->now()->format('Y-m-d H:i:s'));
        $statement->execute();

        $this->keys[$id] = $dek;
        $this->activeKeyId = $id;

        return $id;
    }

    /**
     * The unwrapped DEK for a key id, retired or not.
     *
     * @throws CryptoException when the key row is missing or does not unwrap
     */
    public function keyFor(string $keyId): string
    {
        if (array_key_exists($keyId, $this->keys)) {
            return $this->keys[$keyId];
        }

        $statement = $this->pdo->prepare(sprintf(
            'SELECT id, wrapped_dek, wrap_nonce FROM %s WHERE id = :id',
            self::TABLE
        ));
        $statement->execute([':id' => $keyId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        if (!is_array($row)) {
            throw new CryptoException('The data encryption key this record references is not on the key ring.');
        }

        return $this->keys[$keyId] = $this->unwrap($keyId, $row);
    }

    /**
     * Retire a key: still usable for decryption, never chosen for new writes.
     */
    public function retire(string $keyId): void
    {
        $statement = $this->pdo->prepare(sprintf(
            'UPDATE %s SET retired_at = :retired_at WHERE id = :id AND retired_at IS NULL',
            self::TABLE
        ));
        $statement->execute([
            ':retired_at' => $this->clock->now()->format('Y-m-d H:i:s'),
            ':id' => $keyId,
        ]);

        if ($this->activeKeyId === $keyId) {
            $this->activeKeyId = null;
        }
    }

    /**
     * Drop cached DEKs and the cached active id, so the next call re-reads the
     * table. Used after rotation and by tests.
     */
    public function forget(): void
    {
        $this->keys = [];
        $this->activeKeyId = null;
    }

    private function newestUnretiredKeyId(): ?string
    {
        $statement = $this->pdo->query(sprintf(
            'SELECT id FROM %s WHERE retired_at IS NULL ORDER BY created_at DESC, id DESC LIMIT 1',
            self::TABLE
        ));

        if ($statement === false) {
            throw new CryptoException('Could not read the encryption key ring.');
        }

        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) && isset($row['id']) ? (string) $row['id'] : null;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function unwrap(string $keyId, array $row): string
    {
        $wrapped = Envelope::of(
            $keyId,
            self::bytes($row, 'wrap_nonce'),
            self::bytes($row, 'wrapped_dek')
        );

        $dek = openssl_decrypt(
            $wrapped->body(),
            Crypto::CIPHER,
            $this->masterKey,
            OPENSSL_RAW_DATA,
            $wrapped->nonce,
            $wrapped->tag(),
            Crypto::aad(self::TABLE, $keyId)
        );

        if ($dek === false) {
            throw CryptoException::integrityFailure('wrapped data encryption key');
        }

        if (strlen($dek) !== self::KEY_LENGTH) {
            throw new CryptoException('An unwrapped data encryption key has the wrong length.');
        }

        return $dek;
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function bytes(array $row, string $column): string
    {
        $value = $row[$column] ?? null;

        if (is_resource($value)) {
            $contents = stream_get_contents($value);
            $value = $contents === false ? '' : $contents;
        }

        if (!is_string($value) || $value === '') {
            throw new CryptoException(sprintf('Encryption key row column "%s" must hold bytes.', $column));
        }

        return $value;
    }
}
