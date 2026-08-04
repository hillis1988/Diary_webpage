<?php

declare(strict_types=1);

namespace Diary\Auth;

use Diary\Support\Ulid;
use InvalidArgumentException;
use Stringable;

/**
 * The identifier of a user account: a ULID, kept in a type of its own so an
 * account id cannot be confused with an email address, a session token, or any
 * other 26-character string when it is passed between services.
 *
 * Ids are only ever created here or read back from the database, and the ULID
 * shape is checked both ways, so a malformed id fails at the boundary rather
 * than in a query.
 */
final class UserId implements Stringable
{
    private function __construct(private readonly string $value)
    {
    }

    public static function fromString(string $value): self
    {
        if (!Ulid::isValid($value)) {
            throw new InvalidArgumentException('A user id must be a 26-character ULID.');
        }

        return new self($value);
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
        return $this->value === $other->value;
    }
}
