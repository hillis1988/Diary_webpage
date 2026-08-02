<?php

declare(strict_types=1);

namespace Diary\Support;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * ULID generator: 26 characters of Crockford base32, lexicographically
 * sortable because the leading 48 bits are a millisecond timestamp.
 *
 * Every primary key in the schema is CHAR(26), so ids are sortable by creation
 * time without leaking a sequence count, and can be generated in PHP without a
 * database round trip.
 *
 * Layout: 10 characters of timestamp (48 bits, milliseconds since the Unix
 * epoch) followed by 16 characters of randomness (80 bits). Ids generated
 * within the same millisecond are monotonic: the randomness is incremented
 * rather than redrawn, so ordering never regresses inside a burst.
 */
final class Ulid
{
    /** Crockford base32 alphabet: no I, L, O or U. */
    private const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    public const LENGTH = 26;
    private const TIME_LENGTH = 10;
    private const RANDOM_LENGTH = 16;

    private static ?int $lastMilliseconds = null;
    private static ?string $lastRandomness = null;

    private function __construct()
    {
    }

    /**
     * @param Clock|null $clock injected so tests can pin the timestamp half
     */
    public static function generate(?Clock $clock = null): string
    {
        $milliseconds = self::millisecondsFrom($clock);

        if ($milliseconds === self::$lastMilliseconds && self::$lastRandomness !== null) {
            $randomness = self::increment(self::$lastRandomness);
        } else {
            $randomness = self::randomness();
        }

        self::$lastMilliseconds = $milliseconds;
        self::$lastRandomness = $randomness;

        return self::encodeTimestamp($milliseconds) . $randomness;
    }

    public static function isValid(string $candidate): bool
    {
        if (strlen($candidate) !== self::LENGTH) {
            return false;
        }

        // A leading character above '7' would overflow the 48-bit timestamp.
        if (strpos('01234567', $candidate[0]) === false) {
            return false;
        }

        return strspn($candidate, self::ALPHABET) === self::LENGTH;
    }

    /**
     * The instant encoded in a ULID, to millisecond precision.
     */
    public static function timestampOf(string $ulid): DateTimeImmutable
    {
        if (!self::isValid($ulid)) {
            throw new InvalidArgumentException('Not a valid ULID.');
        }

        $milliseconds = 0;
        for ($i = 0; $i < self::TIME_LENGTH; $i++) {
            $milliseconds = $milliseconds * 32 + (int) strpos(self::ALPHABET, $ulid[$i]);
        }

        return DateTimeImmutable::createFromFormat(
            'U.v',
            sprintf('%d.%03d', intdiv($milliseconds, 1000), $milliseconds % 1000),
            new DateTimeZone('UTC')
        )->setTimezone(new DateTimeZone('UTC'));
    }

    private static function millisecondsFrom(?Clock $clock): int
    {
        if ($clock === null) {
            return (int) (microtime(true) * 1000);
        }

        return (int) $clock->now()->format('Uv');
    }

    private static function encodeTimestamp(int $milliseconds): string
    {
        if ($milliseconds < 0) {
            throw new InvalidArgumentException('A ULID timestamp cannot precede the Unix epoch.');
        }

        $encoded = '';
        for ($i = 0; $i < self::TIME_LENGTH; $i++) {
            $encoded = self::ALPHABET[$milliseconds % 32] . $encoded;
            $milliseconds = intdiv($milliseconds, 32);
        }

        if ($milliseconds !== 0) {
            throw new InvalidArgumentException('A ULID timestamp cannot exceed 48 bits.');
        }

        return $encoded;
    }

    /**
     * 80 bits of cryptographic randomness as 16 base32 characters.
     */
    private static function randomness(): string
    {
        $bytes = random_bytes(10);

        $bits = '';
        for ($i = 0; $i < 10; $i++) {
            $bits .= str_pad(decbin(ord($bytes[$i])), 8, '0', STR_PAD_LEFT);
        }

        $encoded = '';
        foreach (str_split($bits, 5) as $group) {
            $encoded .= self::ALPHABET[bindec($group)];
        }

        return $encoded;
    }

    /**
     * Add one to the randomness half, so ids inside one millisecond keep
     * increasing. On overflow (all Zs) fresh randomness is drawn.
     */
    private static function increment(string $randomness): string
    {
        $characters = str_split($randomness);

        for ($i = self::RANDOM_LENGTH - 1; $i >= 0; $i--) {
            $index = (int) strpos(self::ALPHABET, $characters[$i]);

            if ($index < 31) {
                $characters[$i] = self::ALPHABET[$index + 1];

                return implode('', $characters);
            }

            $characters[$i] = self::ALPHABET[0];
        }

        return self::randomness();
    }
}
