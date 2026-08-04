<?php

declare(strict_types=1);

namespace Diary\Tests\Property;

use Diary\Auth\DefaultPasswordPolicy;
use Diary\Auth\PasswordPolicy;
use Eris\Generator;
use Eris\TestTrait;
use LogicException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Property 1: Password policy is exactly as specified.
 *
 * The policy is checked in both directions against a reference predicate written
 * independently of the implementation: the reference walks the candidate one
 * character at a time and classifies each character on its own, where the policy
 * counts length with `mb_strlen` and scans the whole string with a single
 * pattern. Agreeing on every generated candidate is what pins the policy to
 * "at least 12 characters, at least one letter, at least one digit" - no extra
 * rule sneaking in (banned characters, a maximum length, whitespace trimming)
 * and no rule missing.
 *
 * The interpretation the reference encodes is the one documented on
 * {@see DefaultPasswordPolicy}: characters are Unicode code points, letters are
 * `\p{L}`, digits are `\p{Nd}`, and input that is not valid UTF-8 is judged by
 * bytes and ASCII classes.
 *
 * The "no account is created" half of the property is bounded here to what the
 * policy itself can promise: it is a pure check with no collaborators, so a
 * rejection carries a message and no value and cannot have written anything.
 * The end-to-end claim that a rejected registration leaves no row belongs to
 * Property 2 (task 4.4), which drives the real Auth_Service.
 *
 * Requirements: 1.3, 1.5.
 */
final class PasswordPolicyPropertyTest extends TestCase
{
    use TestTrait;

    /** Letters that are letters under any reading of the rule. */
    private const ASCII_LETTERS = ['a', 'm', 'Z', 'Q'];

    private const ASCII_DIGITS = ['0', '7', '9'];

    /** Non-English letters: Latin-1, Cyrillic, Arabic, Han. */
    private const UNICODE_LETTERS = ['é', 'Ж', 'ب', '漢'];

    /** Decimal digits outside ASCII: Arabic-Indic, Extended Arabic-Indic, Devanagari. */
    private const UNICODE_DIGITS = ['٣', '۵', '१'];

    /** Neither letters nor digits: whitespace, punctuation, currency, symbols. */
    private const NEUTRAL = [' ', "\t", '!', '-', '_', '.', '€', '☂'];

    /** Bytes that cannot stand on their own in UTF-8. */
    private const INVALID_BYTES = ["\xFF", "\xFE", "\x80", "\xC3", "\xE2\x82"];

    private DefaultPasswordPolicy $policy;
    private int $accepted = 0;
    private int $rejected = 0;

    protected function setUp(): void
    {
        $this->policy = new DefaultPasswordPolicy();
        $this->accepted = 0;
        $this->rejected = 0;
    }

    // Feature: mental-health-diary, Property 1: Password policy is exactly as specified
    public function testPasswordPolicyIsExactlyAsSpecified(): void
    {
        // A rejection cannot create an account because the policy has nothing to
        // create one with: no constructor dependencies, so no storage to reach.
        self::assertNull(
            (new ReflectionClass(DefaultPasswordPolicy::class))->getConstructor(),
            'the policy must be a pure check with no collaborators it could write through'
        );

        $this->limitTo(100)
            ->forAll(self::candidatePassword())
            ->then(function (string $candidate): void {
                $shouldBeAccepted = self::meetsPolicy($candidate);
                $result = $this->policy->validate($candidate);

                self::assertSame(
                    $shouldBeAccepted,
                    $result->isOk(),
                    sprintf(
                        'the policy and the rules disagreed on %s',
                        self::describe($candidate)
                    )
                );

                if ($shouldBeAccepted) {
                    ++$this->accepted;

                    self::assertNull($result->errorCode(), 'an accepted password carries no error');
                    self::assertNull($result->message());
                    self::assertSame([], $result->fieldMessages());

                    return;
                }

                ++$this->rejected;

                // A rejection describes the policy, in the one agreed wording,
                // both as the top-level message and against the password field.
                self::assertTrue($result->hasErrorCode(PasswordPolicy::ERROR_CODE));
                self::assertSame($this->policy->describe(), $result->message());
                self::assertSame($this->policy->describe(), $result->fieldMessage(PasswordPolicy::FIELD));

                // Nothing to carry forward: there is no value a caller could
                // mistake for a validated password and go on to store.
                try {
                    $result->value();
                    self::fail('a rejected password must not yield a value to store');
                } catch (LogicException) {
                    // Expected.
                }
            });

        // The run is only evidence of an "if and only if" when it saw both sides.
        self::assertGreaterThan(0, $this->accepted, 'no accepted password was generated');
        self::assertGreaterThan(0, $this->rejected, 'no rejected password was generated');

        // The message has to describe the policy, not merely exist.
        $description = $this->policy->describe();

        self::assertStringContainsString('12', $description);
        self::assertStringContainsString('letter', $description);
        self::assertStringContainsString('digit', $description);
    }

    /**
     * The rules of Requirement 1.5 restated independently of the implementation:
     * split into characters, count them, classify each one.
     */
    private static function meetsPolicy(string $candidate): bool
    {
        $utf8 = mb_check_encoding($candidate, 'UTF-8');

        if ($candidate === '') {
            $characters = [];
        } elseif ($utf8) {
            $characters = mb_str_split($candidate, 1, 'UTF-8');
        } else {
            // Not interpretable as code points, so judged byte by byte.
            $characters = str_split($candidate);
        }

        if (count($characters) < DefaultPasswordPolicy::MINIMUM_LENGTH) {
            return false;
        }

        $isLetter = $utf8
            ? static fn (string $character): bool => preg_match('/^\p{L}$/u', $character) === 1
            : static fn (string $character): bool => ctype_alpha($character);
        $isDigit = $utf8
            ? static fn (string $character): bool => preg_match('/^\p{Nd}$/u', $character) === 1
            : static fn (string $character): bool => ctype_digit($character);

        $hasLetter = false;
        $hasDigit = false;

        foreach ($characters as $character) {
            $hasLetter = $hasLetter || $isLetter($character);
            $hasDigit = $hasDigit || $isDigit($character);
        }

        return $hasLetter && $hasDigit;
    }

    /**
     * Candidates aimed at the places the policy could be wrong: the boundary at
     * twelve, each rule failing on its own, characters the policy must not ban,
     * multi-byte characters that make bytes and characters differ, and bytes
     * that are not text at all.
     */
    private static function candidatePassword(): \Eris\Generator
    {
        return Generator\oneOf(
            // Long enough and carrying both classes: must be accepted.
            self::stringOf(10, 16, self::anyCharacter(), 'a1'),
            // Around the boundary, letters and digits only, so length decides.
            self::stringOf(9, 15, Generator\elements(array_merge(
                self::ASCII_LETTERS,
                self::ASCII_DIGITS,
                self::UNICODE_LETTERS,
                self::UNICODE_DIGITS
            ))),
            // Long but missing a class.
            self::stringOf(12, 18, Generator\elements(array_merge(self::ASCII_LETTERS, self::UNICODE_LETTERS))),
            self::stringOf(12, 18, Generator\elements(array_merge(self::ASCII_DIGITS, self::UNICODE_DIGITS))),
            self::stringOf(12, 18, Generator\elements(self::NEUTRAL)),
            // Anything at all, including empty and unusual mixtures.
            self::stringOf(0, 20, self::anyCharacter()),
            Generator\string(),
            // Byte strings that may not be valid UTF-8.
            self::stringOf(8, 16, Generator\elements(array_merge(
                self::INVALID_BYTES,
                self::ASCII_LETTERS,
                self::ASCII_DIGITS
            )))
        );
    }

    private static function anyCharacter(): \Eris\Generator
    {
        return Generator\elements(array_merge(
            self::ASCII_LETTERS,
            self::ASCII_DIGITS,
            self::UNICODE_LETTERS,
            self::UNICODE_DIGITS,
            self::NEUTRAL
        ));
    }

    /**
     * A string of between $minimum and $maximum generated characters, with
     * $suffix appended.
     */
    private static function stringOf(
        int $minimum,
        int $maximum,
        \Eris\Generator $character,
        string $suffix = ''
    ): \Eris\Generator {
        return Generator\bind(
            Generator\choose($minimum, $maximum),
            static fn (int $length): \Eris\Generator => Generator\map(
                static fn (array $characters): string => implode('', $characters) . $suffix,
                Generator\vector($length, $character)
            )
        );
    }

    /**
     * A failure message that survives bytes which are not printable text.
     */
    private static function describe(string $candidate): string
    {
        return sprintf(
            '%d bytes, hex %s',
            strlen($candidate),
            bin2hex($candidate)
        );
    }
}
