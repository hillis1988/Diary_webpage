<?php

declare(strict_types=1);

namespace Diary\Auth;

use InvalidArgumentException;
use Stringable;

/**
 * The primary key of a `sessions` row: the SHA-256 hex digest of a
 * {@see SessionToken}, which is 64 lowercase hex characters and matches the
 * CHAR(64) column.
 *
 * Deriving the id from the token in one place is what keeps the promise that the
 * raw token is never stored: everything that touches the database takes an id,
 * and an id cannot be turned back into a token.
 */
final class SessionId implements Stringable
{
    public const LENGTH = 64;

    private function __construct(private readonly string $value)
    {
    }

    public static function fromToken(SessionToken $token): self
    {
        return new self(hash('sha256', $token->value()));
    }

    public static function fromString(string $value): self
    {
        if (!self::isWellFormed($value)) {
            throw new InvalidArgumentException('A session id must be 64 lowercase hex characters.');
        }

        return new self($value);
    }

    public static function isWellFormed(string $value): bool
    {
        return preg_match('/^[0-9a-f]{' . self::LENGTH . '}$/', $value) === 1;
    }

    public function toString(): string
    {
        return $this->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }

    public function equals(self $other): bool
    {
        return hash_equals($this->value, $other->value);
    }
}
