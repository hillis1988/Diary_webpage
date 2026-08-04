<?php

declare(strict_types=1);

namespace Diary\Auth;

/**
 * An email address in its two forms, which is the whole of Requirement 1.2:
 *
 * - `normalized()` is the address trimmed and lowercased. It is the only form
 *   the uniqueness check and every lookup ever use, so `  Roy@X.com ` and
 *   `roy@x.com` are one account. The column storing it has a binary collation,
 *   so database equality is exactly equality of this string.
 * - `display()` is the address exactly as the user typed it, stored so the
 *   account shows their own capitalisation back to them.
 *
 * Normalisation is deliberately conservative: whitespace at the ends and letter
 * case are the only things it changes. It does not strip dots, drop `+tags`, or
 * rewrite the domain, because two addresses that differ in those ways can be
 * two different mailboxes.
 */
final class EmailAddress
{
    /** Both `email_normalized` and `email_display` are VARCHAR(255). */
    public const MAX_LENGTH = 255;

    private function __construct(
        private readonly string $display,
        private readonly string $normalized,
    ) {
    }

    /**
     * @param string $raw the address exactly as submitted
     */
    public static function fromInput(string $raw): self
    {
        return new self($raw, self::normalise($raw));
    }

    /**
     * Trim, then lowercase. Exposed separately so a lookup can normalise an
     * address without building an object around it.
     */
    public static function normalise(string $raw): string
    {
        $trimmed = trim($raw);

        // mb_strtolower needs valid UTF-8; anything else is lowercased byte-wise
        // rather than being silently mangled into replacement characters.
        return mb_check_encoding($trimmed, 'UTF-8')
            ? mb_strtolower($trimmed, 'UTF-8')
            : strtolower($trimmed);
    }

    /**
     * As typed, for storage in `email_display`.
     */
    public function display(): string
    {
        return $this->display;
    }

    /**
     * Trimmed and lowercased, for storage in `email_normalized` and for every lookup.
     */
    public function normalized(): string
    {
        return $this->normalized;
    }

    /**
     * Whether this is an address the application will accept (Requirement 1.1).
     *
     * The check is intentionally shallow - a syntactic check plus the column
     * limit. Whether the mailbox actually exists is not knowable here, and
     * over-strict patterns reject legitimate addresses.
     */
    public function isValid(): bool
    {
        if ($this->normalized === '') {
            return false;
        }

        // VARCHAR(255) counts characters, not bytes, so both stored forms are
        // measured in characters. Rejecting here means a long address is refused
        // with a message rather than silently truncated by the database.
        if ($this->length($this->display) > self::MAX_LENGTH
            || $this->length($this->normalized) > self::MAX_LENGTH
        ) {
            return false;
        }

        return filter_var($this->normalized, FILTER_VALIDATE_EMAIL) !== false;
    }

    private function length(string $value): int
    {
        return mb_check_encoding($value, 'UTF-8') ? mb_strlen($value, 'UTF-8') : strlen($value);
    }
}
