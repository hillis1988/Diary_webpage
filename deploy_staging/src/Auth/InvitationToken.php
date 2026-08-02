<?php

declare(strict_types=1);

namespace Diary\Auth;

use InvalidArgumentException;

/**
 * The single-use token an owner-created Viewer uses to set their own password
 * (Requirement 7.1).
 *
 * Shaped exactly like {@see SessionToken}, and for the same reason: 256 bits of
 * cryptographic randomness, carried as 64 lowercase hex characters, with only its
 * SHA-256 hash ever reaching the database (`users.invitation_token_hash`). A ULID
 * is deliberately not used here - a ULID's leading bits are a timestamp, which
 * makes it guessable, and this value has to be an unguessable secret, not a
 * sortable identifier.
 *
 * Not Stringable, so a token cannot be interpolated into a log line or a
 * template by accident.
 */
final class InvitationToken
{
    /** 32 bytes of randomness, matching {@see SessionToken::BYTES}. */
    public const BYTES = 32;

    /** Hex encoding of {@see BYTES}. */
    public const LENGTH = 64;

    private function __construct(private readonly string $value)
    {
    }

    public static function generate(): self
    {
        return new self(bin2hex(random_bytes(self::BYTES)));
    }

    public static function fromString(string $value): self
    {
        if (!self::isWellFormed($value)) {
            throw new InvalidArgumentException('An invitation token must be 64 lowercase hex characters.');
        }

        return new self($value);
    }

    /**
     * For values arriving from a form field, where a malformed token is an
     * ordinary "invalid invitation" rather than a programming error.
     */
    public static function tryFromString(string $value): ?self
    {
        return self::isWellFormed($value) ? new self($value) : null;
    }

    public static function isWellFormed(string $value): bool
    {
        return preg_match('/^[0-9a-f]{' . self::LENGTH . '}$/', $value) === 1;
    }

    public function value(): string
    {
        return $this->value;
    }

    /**
     * The SHA-256 hex digest stored in `users.invitation_token_hash`.
     */
    public function hash(): string
    {
        return hash('sha256', $this->value);
    }
}
