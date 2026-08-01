<?php

declare(strict_types=1);

namespace Diary\Tests\Unit\Auth;

use Diary\Auth\PasswordHasher;
use PHPUnit\Framework\TestCase;

/**
 * Requirement 1.4: the password is stored using a one-way salted hash. What is
 * pinned here is that the algorithm is Argon2id where the build allows it and
 * bcrypt otherwise, that a hash is salted (so two hashes of one password differ),
 * and that the stored value fits the VARCHAR(255) column.
 */
final class PasswordHasherTest extends TestCase
{
    private const PASSWORD = 'correct1horse2battery';

    public function testPrefersArgon2idAndFallsBackToBcrypt(): void
    {
        $expected = defined('PASSWORD_ARGON2ID') && in_array(PASSWORD_ARGON2ID, password_algos(), true)
            ? PASSWORD_ARGON2ID
            : PASSWORD_BCRYPT;

        self::assertSame($expected, PasswordHasher::preferredAlgorithm());
        self::assertSame($expected, (new PasswordHasher())->algorithm());
    }

    public function testTheHashIsNeitherThePasswordNorReversible(): void
    {
        $hash = (new PasswordHasher())->hash(self::PASSWORD);

        self::assertNotSame(self::PASSWORD, $hash);
        self::assertStringNotContainsString(self::PASSWORD, $hash);
        self::assertLessThanOrEqual(255, strlen($hash));

        $information = password_get_info($hash);
        self::assertSame((new PasswordHasher())->algorithm(), $information['algo']);
    }

    public function testHashingTheSamePasswordTwiceGivesDifferentHashes(): void
    {
        $hasher = new PasswordHasher();

        self::assertNotSame($hasher->hash(self::PASSWORD), $hasher->hash(self::PASSWORD));
    }

    public function testVerificationAcceptsThePasswordAndRejectsEverythingElse(): void
    {
        $hasher = new PasswordHasher();
        $hash = $hasher->hash(self::PASSWORD);

        self::assertTrue($hasher->verify(self::PASSWORD, $hash));
        self::assertFalse($hasher->verify(self::PASSWORD . 'x', $hash));
        self::assertFalse($hasher->verify('', $hash));
    }

    public function testAnAccountWithNoPasswordVerifiesAgainstNothing(): void
    {
        $hasher = new PasswordHasher();

        self::assertFalse($hasher->verify(self::PASSWORD, null));
        self::assertFalse($hasher->verify(self::PASSWORD, ''));
    }

    public function testBcryptRemainsUsableAsTheExplicitFallback(): void
    {
        $hasher = new PasswordHasher(PASSWORD_BCRYPT);
        $hash = $hasher->hash(self::PASSWORD);

        self::assertSame(PASSWORD_BCRYPT, $hasher->algorithm());
        self::assertTrue($hasher->verify(self::PASSWORD, $hash));
        self::assertFalse($hasher->needsRehash($hash));
    }
}
