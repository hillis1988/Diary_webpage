<?php

declare(strict_types=1);

namespace Diary\Tests\Unit\Support;

use Diary\Support\FixedClock;
use Diary\Support\Ulid;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Every primary key in the schema is CHAR(26), and rows are ordered by id, so
 * the shape of a ULID and its sortability are load-bearing.
 */
final class UlidTest extends TestCase
{
    /** Crockford base32: no I, L, O or U. */
    private const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    public function testAGeneratedUlidIs26CrockfordCharacters(): void
    {
        $ulid = Ulid::generate();

        self::assertSame(26, Ulid::LENGTH);
        self::assertSame(26, strlen($ulid));
        self::assertSame(26, strspn($ulid, self::ALPHABET), 'every character comes from the alphabet');
        self::assertTrue(Ulid::isValid($ulid));
    }

    public function testTheTimestampHalfComesFromTheClock(): void
    {
        $clock = FixedClock::at('2024-02-29 12:34:56');

        $first = Ulid::generate($clock);
        $second = Ulid::generate($clock);

        self::assertSame(
            substr($first, 0, 10),
            substr($second, 0, 10),
            'ids minted in the same millisecond share the 10-character timestamp'
        );
        self::assertSame(
            '2024-02-29 12:34:56.000',
            Ulid::timestampOf($first)->format('Y-m-d H:i:s.v')
        );
        self::assertSame('UTC', Ulid::timestampOf($first)->getTimezone()->getName());
    }

    public function testIdsMintedInOneMillisecondStillSortInOrder(): void
    {
        $clock = FixedClock::at('2024-02-29 12:34:56');

        $ids = [];
        for ($i = 0; $i < 5; $i++) {
            $ids[] = Ulid::generate($clock);
        }

        $sorted = $ids;
        sort($sorted, SORT_STRING);

        self::assertSame($sorted, $ids, 'monotonic inside a millisecond');
        self::assertCount(5, array_unique($ids));
    }

    public function testLaterIdsSortAfterEarlierOnes(): void
    {
        $clock = FixedClock::at('2024-02-29 12:34:56');
        $earlier = Ulid::generate($clock);

        $clock->advanceSeconds(1);
        $later = Ulid::generate($clock);

        self::assertGreaterThan(0, strcmp($later, $earlier), 'lexicographic order follows time');
        self::assertSame(1, Ulid::timestampOf($later)->getTimestamp() - Ulid::timestampOf($earlier)->getTimestamp());
    }

    public function testValidationRejectsTheWrongLength(): void
    {
        self::assertFalse(Ulid::isValid(''));
        self::assertFalse(Ulid::isValid(str_repeat('0', 25)));
        self::assertFalse(Ulid::isValid(str_repeat('0', 27)));
    }

    public function testValidationRejectsCharactersOutsideTheAlphabet(): void
    {
        // I, L, O and U are excluded from Crockford base32; lowercase is not used.
        foreach (['I', 'L', 'O', 'U', 'a', '-', ' '] as $character) {
            self::assertFalse(
                Ulid::isValid('01ARZ3NDEKTSV4RRFFQ69G5FA' . $character),
                sprintf('"%s" is not a ULID character', $character)
            );
        }
    }

    public function testValidationRejectsATimestampThatWouldOverflow48Bits(): void
    {
        self::assertTrue(Ulid::isValid('7ZZZZZZZZZZZZZZZZZZZZZZZZZ'), 'the largest representable timestamp');
        self::assertFalse(Ulid::isValid('8ZZZZZZZZZZZZZZZZZZZZZZZZZ'), 'a leading 8 overflows 48 bits');
        self::assertFalse(Ulid::isValid('ZZZZZZZZZZZZZZZZZZZZZZZZZZ'));
    }

    public function testTheEpochEncodesAsAllZeroTimestampCharacters(): void
    {
        self::assertSame(0, Ulid::timestampOf('00000000000000000000000000')->getTimestamp());
    }

    public function testReadingTheTimestampOfAMalformedIdFails(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Ulid::timestampOf('not-a-ulid');
    }
}
