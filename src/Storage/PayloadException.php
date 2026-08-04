<?php

declare(strict_types=1);

namespace Diary\Storage;

/**
 * A payload that does not match the shape its table expects: a missing or
 * out-of-range field, an unknown key, or a `schema_version` this build cannot
 * read (Requirements 4.1, 4.2).
 *
 * Like a failed decryption this is an exception rather than a Result: a payload
 * that cannot be understood must not be half-rendered, and a payload that cannot
 * be validated must not be written.
 *
 * Messages name fields and constraints only. They never carry the value that was
 * rejected, because that value is diary content.
 */
final class PayloadException extends StorageException
{
    public static function missingField(PayloadShape $shape, string $field): self
    {
        return new self(sprintf('A %s payload requires the "%s" field.', $shape->table(), $field));
    }

    public static function invalidField(PayloadShape $shape, string $field, string $expectation): self
    {
        return new self(sprintf('The "%s" field of a %s payload %s.', $field, $shape->table(), $expectation));
    }

    /**
     * @param list<string> $unknown
     */
    public static function unknownFields(PayloadShape $shape, array $unknown): self
    {
        return new self(sprintf(
            'A %s payload carries fields the schema does not define: %s.',
            $shape->table(),
            implode(', ', $unknown)
        ));
    }

    public static function unsupportedSchemaVersion(PayloadShape $shape, string $found, int $supported): self
    {
        return new self(sprintf(
            'A %s payload declares schema version %s; this build reads version %d.',
            $shape->table(),
            $found,
            $supported
        ));
    }

    public static function malformedStoredPayload(PayloadShape $shape): self
    {
        return new self(sprintf(
            'A stored %s payload is not a readable JSON document and was not rendered.',
            $shape->table()
        ));
    }

    public static function storedPayloadRejected(PayloadShape $shape, self $cause): self
    {
        return new self(
            sprintf('A stored %s payload does not match the schema and was not rendered. ', $shape->table())
                . $cause->getMessage(),
            0,
            $cause
        );
    }

    public static function notUtf8(PayloadShape $shape): self
    {
        return new self(sprintf('A %s payload contains text that is not valid UTF-8.', $shape->table()));
    }

    public static function partiallyEncrypted(PayloadShape $shape): self
    {
        return new self(sprintf(
            'A %s row has only some of its key_id, nonce and payload_ciphertext columns set.',
            $shape->table()
        ));
    }
}
