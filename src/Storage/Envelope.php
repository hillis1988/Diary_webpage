<?php

declare(strict_types=1);

namespace Diary\Storage;

/**
 * The three stored parts of an encrypted payload: which data key sealed it, the
 * nonce used, and the ciphertext with its authentication tag appended.
 *
 * Every sensitive table (`diary_entries`, `milestones`, `cbt_recommendations`)
 * carries exactly these columns: `key_id`, `nonce`, `payload_ciphertext`. The
 * GCM tag has no column of its own, so it travels as the last 16 bytes of
 * `payload_ciphertext`; splitting it back off is this class's job rather than
 * every repository's.
 *
 * The value object is immutable and validates its own shape, so a mapping
 * mistake (nonce in the ciphertext column, a truncated blob) fails at the
 * boundary instead of surfacing as a mysterious authentication failure.
 *
 * Requirements: 4.1, 4.2.
 */
final class Envelope
{
    /** AES-GCM nonce length in bytes; the `nonce` columns are BINARY(12). */
    public const NONCE_LENGTH = 12;

    /** AES-GCM authentication tag length in bytes. */
    public const TAG_LENGTH = 16;

    /** Length of the CHAR(26) ULID primary key of `encryption_keys`. */
    private const KEY_ID_LENGTH = 26;

    private function __construct(
        public readonly string $keyId,
        public readonly string $nonce,
        public readonly string $ciphertext
    ) {
    }

    /**
     * @param string $keyId      `encryption_keys.id`
     * @param string $nonce      exactly 12 raw bytes
     * @param string $ciphertext raw ciphertext with the 16-byte tag appended
     */
    public static function of(string $keyId, string $nonce, string $ciphertext): self
    {
        if (strlen($keyId) !== self::KEY_ID_LENGTH) {
            throw new CryptoException('An envelope key id must be a 26-character key identifier.');
        }

        if (strlen($nonce) !== self::NONCE_LENGTH) {
            throw new CryptoException(sprintf('An envelope nonce must be exactly %d bytes.', self::NONCE_LENGTH));
        }

        if (strlen($ciphertext) < self::TAG_LENGTH) {
            throw new CryptoException('An envelope ciphertext is too short to carry an authentication tag.');
        }

        return new self($keyId, $nonce, $ciphertext);
    }

    /**
     * Build from the ciphertext and tag as OpenSSL hands them back separately.
     */
    public static function fromParts(string $keyId, string $nonce, string $ciphertext, string $tag): self
    {
        if (strlen($tag) !== self::TAG_LENGTH) {
            throw new CryptoException(sprintf('An authentication tag must be exactly %d bytes.', self::TAG_LENGTH));
        }

        return self::of($keyId, $nonce, $ciphertext . $tag);
    }

    /**
     * Read the three columns of a fetched row.
     *
     * @param array<string, mixed> $row
     * @param string               $ciphertextColumn the payload column name; every current table uses payload_ciphertext
     */
    public static function fromRow(
        array $row,
        string $ciphertextColumn = 'payload_ciphertext',
        string $keyIdColumn = 'key_id',
        string $nonceColumn = 'nonce'
    ): self {
        return self::of(
            self::column($row, $keyIdColumn),
            self::column($row, $nonceColumn),
            self::column($row, $ciphertextColumn)
        );
    }

    /**
     * The column values to bind on a write, in schema order.
     *
     * @return array{key_id: string, nonce: string, payload_ciphertext: string}
     */
    public function toRow(): array
    {
        return [
            'key_id' => $this->keyId,
            'nonce' => $this->nonce,
            'payload_ciphertext' => $this->ciphertext,
        ];
    }

    /**
     * Ciphertext without the trailing authentication tag.
     */
    public function body(): string
    {
        return substr($this->ciphertext, 0, -self::TAG_LENGTH);
    }

    public function tag(): string
    {
        return substr($this->ciphertext, -self::TAG_LENGTH);
    }

    /**
     * The cipher every envelope is produced with. Fixed rather than stored: a
     * future change of cipher gets a new schema_version and an explicit
     * migration, not a per-row negotiation.
     */
    public function cipher(): string
    {
        return Crypto::CIPHER;
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function column(array $row, string $column): string
    {
        if (!array_key_exists($column, $row)) {
            throw new CryptoException(sprintf('Encrypted row is missing the "%s" column.', $column));
        }

        $value = $row[$column];

        if (is_resource($value)) {
            // Some drivers hand back BLOBs as streams.
            $contents = stream_get_contents($value);
            $value = $contents === false ? '' : $contents;
        }

        if (!is_string($value)) {
            throw new CryptoException(sprintf('Encrypted row column "%s" must hold bytes.', $column));
        }

        return $value;
    }
}
