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

    /**
     * The test-only factory: cheap enough for property tests, still a salted
     * one-way hash, and no influence at all on what production uses.
     */
    public function testTheTestOnlyHasherIsMinimumCostBcryptAndStillSalted(): void
    {
        $hasher = PasswordHasher::forTests();
        $hash = $hasher->hash(self::PASSWORD);
        $information = password_get_info($hash);

        self::assertSame(PASSWORD_BCRYPT, $hasher->algorithm());
        self::assertSame('bcrypt', $information['algoName']);
        self::assertSame(PasswordHasher::TEST_BCRYPT_COST, (int) ($information['options']['cost'] ?? 0));
        self::assertTrue($hasher->verify(self::PASSWORD, $hash));
        self::assertFalse($hasher->verify(self::PASSWORD . 'x', $hash));
        self::assertNotSame(
            $hash,
            $hasher->hash(self::PASSWORD),
            'even at the minimum cost every hash carries its own salt'
        );
        self::assertFalse($hasher->needsRehash($hash), 'a test hash matches the test parameters');

        // Production is untouched by the factory existing: the default hasher is
        // still the preferred algorithm at its real work factors, and it would
        // upgrade a hash made this cheaply at the next sign-in.
        self::assertSame(PasswordHasher::preferredAlgorithm(), (new PasswordHasher())->algorithm());
        self::assertTrue(
            (new PasswordHasher())->needsRehash($hash),
            'production must treat a minimum-cost hash as due for rehashing'
        );
    }
}
