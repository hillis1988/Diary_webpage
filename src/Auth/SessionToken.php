<?php

declare(strict_types=1);

namespace Diary\Auth;

use InvalidArgumentException;

/**
 * The opaque session token that travels in the cookie.
 *
 * 256 bits of cryptographic randomness, carried as 64 lowercase hex characters.
 * It is a secret: the database stores only {@see SessionId}, the SHA-256 of this
 * value, so a database disclosure yields no usable cookie. The class is
 * deliberately not Stringable, so a token cannot be interpolated into a log line
 * or a template by accident - reading it takes an explicit `value()` call.
 */
final class SessionToken
{
    /** 32 bytes of randomness, per the design's 256-bit token. */
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
            throw new InvalidArgumentException('A session token must be 64 lowercase hex characters.');
        }

        return new self($value);
    }

    /**
     * For values arriving from a cookie, where a malformed token is an ordinary
     * "not signed in" rather than a programming error.
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
     * The identifier this token is stored under.
     */
    public function id(): SessionId
    {
        return SessionId::fromToken($this);
    }
}
