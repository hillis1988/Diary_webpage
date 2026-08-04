<?php

declare(strict_types=1);

namespace Diary\Milestone;

use Diary\Support\Ulid;
use InvalidArgumentException;
use Stringable;

/**
 * The identifier of a milestone row: a ULID, kept in a type of its own so a
 * milestone id cannot be confused with any other 26-character identifier
 * (an owner id, a diary entry id) when it is passed between the repository,
 * service and controller.
 */
final class MilestoneId implements Stringable
{
    private function __construct(private readonly string $value)
    {
    }

    public static function fromString(string $value): self
    {
        if (!Ulid::isValid($value)) {
            throw new InvalidArgumentException('A milestone id must be a 26-character ULID.');
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
