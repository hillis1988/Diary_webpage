<?php

declare(strict_types=1);

namespace Diary\Storage;

/**
 * An encryption-layer fault: a missing or malformed master key, an unknown or
 * unusable data key, or a payload whose authentication tag does not verify.
 *
 * A failed decryption is deliberately an exception rather than a Result. There
 * is no sensible partial answer to "this row does not authenticate": the caller
 * must not render anything, so the failure has to be impossible to ignore
 * (Requirements 4.1, 4.2).
 *
 * Messages never carry key material, plaintext, or ciphertext.
 */
final class CryptoException extends StorageException
{
    public static function integrityFailure(string $context): self
    {
        return new self(sprintf(
            'Stored data failed its integrity check (%s); it has been altered or is bound to a different record.',
            $context
        ));
    }
}
