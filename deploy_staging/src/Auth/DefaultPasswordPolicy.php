<?php

declare(strict_types=1);

namespace Diary\Auth;

use Diary\Support\Result;

/**
 * The policy specified by Requirement 1.5: at least 12 characters, including at
 * least one letter and at least one digit. Nothing else is required and nothing
 * else is forbidden - no maximum length, no character is banned, and whitespace
 * counts like any other character, so a passphrase passes on length alone once
 * it carries a digit.
 *
 * Interpretation of "characters", stated here because it is the contract the
 * property test asserts against:
 * - length is counted in Unicode code points (`mb_strlen`), not bytes, so a
 *   twelve-character password of accented letters is twelve characters;
 * - "letter" is any Unicode letter (`\p{L}`), "digit" any Unicode decimal digit
 *   (`\p{Nd}`), so a non-English password is judged by the same rules;
 * - input that is not valid UTF-8 cannot be interpreted as code points at all,
 *   so it falls back to bytes and ASCII classes rather than being accepted or
 *   rejected outright.
 */
final class DefaultPasswordPolicy implements PasswordPolicy
{
    public const MINIMUM_LENGTH = 12;

    /**
     * The exact wording shown on rejection (error catalogue, Requirement 1.3).
     */
    public const MESSAGE = 'Your password must be at least 12 characters long and include at least one letter and at least one digit.';

    /**
     * @return Result<null>
     */
    public function validate(string $password): Result
    {
        if ($this->length($password) >= self::MINIMUM_LENGTH
            && $this->containsLetter($password)
            && $this->containsDigit($password)
        ) {
            return Result::ok(null);
        }

        return Result::failure(
            self::ERROR_CODE,
            self::MESSAGE,
            [self::FIELD => self::MESSAGE],
        );
    }

    public function describe(): string
    {
        return self::MESSAGE;
    }

    private function length(string $password): int
    {
        if (!$this->isUtf8($password)) {
            return strlen($password);
        }

        return mb_strlen($password, 'UTF-8');
    }

    private function containsLetter(string $password): bool
    {
        if (!$this->isUtf8($password)) {
            return preg_match('/[A-Za-z]/', $password) === 1;
        }

        return preg_match('/\p{L}/u', $password) === 1;
    }

    private function containsDigit(string $password): bool
    {
        if (!$this->isUtf8($password)) {
            return preg_match('/[0-9]/', $password) === 1;
        }

        return preg_match('/\p{Nd}/u', $password) === 1;
    }

    private function isUtf8(string $password): bool
    {
        return mb_check_encoding($password, 'UTF-8');
    }
}
