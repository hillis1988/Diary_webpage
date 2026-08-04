<?php

declare(strict_types=1);

namespace Diary\Access;

use Diary\Auth\UserId;
use Diary\Support\Ulid;
use InvalidArgumentException;
use Stringable;

/**
 * Whose data a query may touch.
 *
 * It is a ULID like a {@see UserId}, and deliberately a different type: every
 * repository method takes an `OwnerId`, so a handler cannot pass the id of the
 * *signed-in user* where the scope of the *data owner* belongs. In a viewer
 * session those two differ, which is exactly the mistake this type exists to make
 * impossible (Requirement 4.4).
 *
 * The only sanctioned way to obtain one is
 * {@see AccessControlService::resolveDataOwner()}. {@see fromString()} exists for
 * ids read back out of the database, not for widening a scope by hand.
 */
final class OwnerId implements Stringable
{
    private function __construct(private readonly string $value)
    {
    }

    /**
     * The session's `dataOwnerId`, promoted to a scope.
     */
    public static function fromUserId(UserId $userId): self
    {
        return new self($userId->toString());
    }

    public static function fromString(string $value): self
    {
        if (!Ulid::isValid($value)) {
            throw new InvalidArgumentException('An owner id must be a 26-character ULID.');
        }

        return new self($value);
    }

    public function toString(): string
    {
        return $this->value;
    }

    public function toUserId(): UserId
    {
        return UserId::fromString($this->value);
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
