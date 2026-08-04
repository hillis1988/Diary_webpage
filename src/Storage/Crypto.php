<?php

declare(strict_types=1);

namespace Diary\Storage;

/**
 * Authenticated encryption of sensitive payloads (Requirements 4.1, 4.2).
 *
 * AES-256-GCM, with a data key taken from the KeyRing, a freshly drawn 12-byte
 * nonce on every single write, and the record's table name plus id as
 * additional authenticated data.
 *
 * The AAD is the part worth dwelling on: it is not encrypted, but it is
 * authenticated, so a ciphertext copied from one row to another - a different
 * entry, or another user's entry, or the same id in a different table - fails to
 * decrypt instead of quietly yielding someone else's health data. Row-level
 * confidentiality plus row-level binding is what makes shared hosting with no
 * verifiable disk encryption acceptable.
 *
 * Nonces are random rather than counted. A counter needs coordinated state that
 * shared hosting cannot guarantee; at one diary entry per day the birthday bound
 * on a 96-bit random nonce is not a practical concern, and every rotation
 * introduces a fresh key anyway.
 */
final class Crypto
{
    public const CIPHER = 'aes-256-gcm';

    public function __construct(private readonly KeyRing $keyRing)
    {
    }

    /**
     * The additional authenticated data binding a ciphertext to one row.
     *
     * NUL-separated so no pair of (table, id) values can collide with another
     * pair by concatenation.
     */
    public static function aad(string $table, string $recordId): string
    {
        if ($table === '' || $recordId === '') {
            throw new CryptoException('Encryption requires both a table name and a record id as its binding.');
        }

        return $table . "\0" . $recordId;
    }

    /**
     * @param string $plaintext the JSON payload document
     * @param string $aad       from self::aad(), i.e. table name plus record id
     */
    public function encrypt(string $plaintext, string $aad): Envelope
    {
        if ($aad === '') {
            throw new CryptoException('Encryption requires a record binding as additional authenticated data.');
        }

        $keyId = $this->keyRing->ensureActiveKey();
        $nonce = random_bytes(Envelope::NONCE_LENGTH);
        $tag = '';

        $ciphertext = openssl_encrypt(
            $plaintext,
            self::CIPHER,
            $this->keyRing->keyFor($keyId),
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
            $aad,
            Envelope::TAG_LENGTH
        );

        if ($ciphertext === false) {
            throw new CryptoException('Could not encrypt the payload.');
        }

        return Envelope::fromParts($keyId, $nonce, $ciphertext, $tag);
    }

    /**
     * @param string $aad must be the same binding the envelope was written with
     *
     * @throws CryptoException when the key is unavailable, or the ciphertext,
     *                         nonce, tag or binding does not authenticate
     */
    public function decrypt(Envelope $envelope, string $aad): string
    {
        if ($aad === '') {
            throw new CryptoException('Decryption requires a record binding as additional authenticated data.');
        }

        $plaintext = openssl_decrypt(
            $envelope->body(),
            self::CIPHER,
            $this->keyRing->keyFor($envelope->keyId),
            OPENSSL_RAW_DATA,
            $envelope->nonce,
            $envelope->tag(),
            $aad
        );

        if ($plaintext === false) {
            throw CryptoException::integrityFailure('encrypted payload');
        }

        return $plaintext;
    }

    /**
     * Convenience for the common call shape at the repository boundary.
     */
    public function encryptFor(string $table, string $recordId, string $plaintext): Envelope
    {
        return $this->encrypt($plaintext, self::aad($table, $recordId));
    }

    public function decryptFor(string $table, string $recordId, Envelope $envelope): string
    {
        return $this->decrypt($envelope, self::aad($table, $recordId));
    }
}
