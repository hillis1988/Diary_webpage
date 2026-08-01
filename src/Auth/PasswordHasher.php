<?php

declare(strict_types=1);

namespace Diary\Auth;

use RuntimeException;

/**
 * One-way password hashing (Requirement 1.4).
 *
 * Argon2id is used when the PHP build offers it, and bcrypt otherwise, because
 * shared hosting cannot be relied on to have libsodium or the Argon2 library
 * compiled in. Both algorithms salt every hash themselves, so the stored value
 * is a self-describing string carrying algorithm, cost parameters and salt, and
 * hashing the same password twice produces two different hashes.
 *
 * The plaintext password never leaves this class and is never stored, logged or
 * returned anywhere.
 */
final class PasswordHasher
{
    /**
     * Work factors. The Argon2id figures are PHP's defaults, stated explicitly so
     * a change is a deliberate edit rather than a side effect of a PHP upgrade.
     * The bcrypt cost is raised one step above PHP's default.
     */
    public const ARGON2_MEMORY_COST = 65536;
    public const ARGON2_TIME_COST = 4;
    public const ARGON2_THREADS = 1;
    public const BCRYPT_COST = 11;

    /**
     * bcrypt's own minimum cost. Deliberately far too weak for real credentials,
     * and used by nothing but {@see forTests()}.
     */
    public const TEST_BCRYPT_COST = 4;

    private readonly string $algorithm;

    /**
     * Work factors replacing the defaults for {@see $algorithm}, or null to use
     * them. Only tests pass this.
     *
     * @var array<string, int>|null
     */
    private readonly ?array $optionOverrides;

    /**
     * @param string|null            $algorithm a `PASSWORD_*` algorithm identifier; defaults
     *                                          to Argon2id where available, bcrypt otherwise
     * @param array<string, int>|null $options  work factors overriding this class's
     *                                          defaults. Intended for tests - see
     *                                          {@see forTests()} - and never for
     *                                          production, which wants the defaults.
     */
    public function __construct(?string $algorithm = null, ?array $options = null)
    {
        $this->algorithm = $algorithm ?? self::preferredAlgorithm();
        $this->optionOverrides = $options;
    }

    /**
     * A hasher for test suites only.
     *
     * Test-only, and named so nothing reaches for it by accident: bcrypt at its
     * minimum cost, which is still a salted one-way hash - so tests about how a
     * credential is stored remain honest - but cheap enough to run thousands of
     * times in a property test. Production code must use the constructor, which
     * defaults to {@see preferredAlgorithm()} and its real work factors.
     */
    public static function forTests(): self
    {
        return new self(PASSWORD_BCRYPT, ['cost' => self::TEST_BCRYPT_COST]);
    }

    /**
     * Argon2id when this PHP build supports it, bcrypt as the fallback.
     */
    public static function preferredAlgorithm(): string
    {
        if (defined('PASSWORD_ARGON2ID') && in_array(PASSWORD_ARGON2ID, password_algos(), true)) {
            return PASSWORD_ARGON2ID;
        }

        return PASSWORD_BCRYPT;
    }

    public function algorithm(): string
    {
        return $this->algorithm;
    }

    /**
     * @return non-empty-string the salted one-way hash to store
     */
    public function hash(string $password): string
    {
        $hash = password_hash($password, $this->algorithm, $this->options());

        if ($hash === '' || strlen($hash) > 255) {
            // password_hash throws on failure in PHP 8, so this is a guard against
            // a hash that would not survive the VARCHAR(255) column rather than an
            // expected path.
            throw new RuntimeException('The password hash could not be produced in a storable form.');
        }

        return $hash;
    }

    /**
     * Constant-time comparison of a candidate password against a stored hash.
     * A null or empty hash - an invited viewer who has not set a password yet -
     * verifies against nothing.
     */
    public function verify(string $password, ?string $hash): bool
    {
        if ($hash === null || $hash === '') {
            return false;
        }

        return password_verify($password, $hash);
    }

    /**
     * Whether a stored hash was made with weaker parameters than the current ones,
     * so it can be upgraded on the next successful sign-in.
     */
    public function needsRehash(string $hash): bool
    {
        return password_needs_rehash($hash, $this->algorithm, $this->options());
    }

    /**
     * @return array<string, int>
     */
    private function options(): array
    {
        if ($this->optionOverrides !== null) {
            return $this->optionOverrides;
        }

        if (defined('PASSWORD_ARGON2ID') && $this->algorithm === PASSWORD_ARGON2ID) {
            return [
                'memory_cost' => self::ARGON2_MEMORY_COST,
                'time_cost' => self::ARGON2_TIME_COST,
                'threads' => self::ARGON2_THREADS,
            ];
        }

        if ($this->algorithm === PASSWORD_BCRYPT) {
            return ['cost' => self::BCRYPT_COST];
        }

        return [];
    }
}
