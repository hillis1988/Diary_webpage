<?php

declare(strict_types=1);

namespace Diary\Tests\Integration;

use Diary\Storage\ConnectionFactory;
use Diary\Storage\StorageException;
use PDO;
use PDOException;

/**
 * Creates and drops a throwaway MariaDB schema for integration tests.
 *
 * Connection details come from the DIARY_TEST_DB_* environment variables that
 * phpunit.xml sets. The helper never touches the configured schema itself: it
 * creates a per-run schema named after it with a random suffix, so pointing the
 * variables at a server that also holds other databases cannot destroy
 * anything. The schema is dropped again when the test finishes.
 *
 * When no server answers - which is the normal case on a development machine
 * without MariaDB installed - `unavailableReason()` explains why, and the
 * calling test skips with that message instead of failing. In CI, where a
 * MariaDB service is running, the same tests execute for real.
 */
final class MariaDbTestSchema
{
    /** Seconds to wait for the server before deciding it is not there. */
    private const CONNECT_TIMEOUT = 3;

    /** Memoised across the run: the server does not appear mid-suite. */
    private static bool $probed = false;
    private static ?string $unavailableReason = null;

    private ?PDO $connection = null;

    private function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly string $user,
        private readonly string $password,
        private readonly string $schema,
    ) {
    }

    /**
     * Reads the DIARY_TEST_DB_* variables. Returns null when the settings are
     * unusable, in which case `unavailableReason()` says so.
     */
    public static function fromEnvironment(): ?self
    {
        $host = self::env('DIARY_TEST_DB_HOST');
        $baseName = self::env('DIARY_TEST_DB_NAME');
        $user = self::env('DIARY_TEST_DB_USER');

        if ($host === null || $host === '' || $baseName === null || $baseName === '' || $user === null || $user === '') {
            return null;
        }

        if (preg_match('/^[A-Za-z0-9_]+$/', $baseName) !== 1) {
            return null;
        }

        $port = self::env('DIARY_TEST_DB_PORT');

        return new self(
            $host,
            $port === null || $port === '' ? 3306 : (int) $port,
            $user,
            self::env('DIARY_TEST_DB_PASSWORD') ?? '',
            // A distinct schema per run, so parallel runs and any pre-existing
            // test data stay out of each other's way. MariaDB allows 64
            // characters for a schema name.
            substr($baseName . '_mig_' . bin2hex(random_bytes(4)), 0, 64)
        );
    }

    /**
     * Why these tests cannot run here, or null when they can.
     */
    public static function unavailableReason(): ?string
    {
        if (!self::$probed) {
            self::$unavailableReason = self::probe();
            self::$probed = true;
        }

        return self::$unavailableReason;
    }

    private static function probe(): ?string
    {
        if (!in_array('mysql', PDO::getAvailableDrivers(), true)) {
            return 'pdo_mysql is not loaded, so no MariaDB integration test can run.';
        }

        $schema = self::fromEnvironment();

        if ($schema === null) {
            return 'DIARY_TEST_DB_HOST, DIARY_TEST_DB_NAME and DIARY_TEST_DB_USER must be set '
                . 'to a throwaway MariaDB schema (see phpunit.xml).';
        }

        try {
            $schema->connectToServer();
        } catch (StorageException $failure) {
            return sprintf(
                'No MariaDB server answered at %s:%d as user "%s" (%s). '
                . 'Start a throwaway MariaDB instance and set the DIARY_TEST_DB_* variables to run this test.',
                $schema->host,
                $schema->port,
                $schema->user,
                $failure->getMessage()
            );
        }

        return null;
    }

    /**
     * Creates the throwaway schema and returns a connection to it.
     */
    public function create(): PDO
    {
        $server = $this->connectToServer();

        // The name is generated from a validated base name plus hex, so it holds
        // no quoting hazard; identifiers cannot be bound as parameters anyway.
        $server->exec(sprintf(
            'CREATE DATABASE `%s` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
            $this->schema
        ));

        return $this->connection = ConnectionFactory::fromDsn(
            sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                $this->host,
                $this->port,
                $this->schema
            ),
            $this->user,
            $this->password,
            [PDO::ATTR_TIMEOUT => self::CONNECT_TIMEOUT]
        );
    }

    /**
     * Drops the throwaway schema. Safe to call when `create()` never ran.
     */
    public function drop(): void
    {
        $this->connection = null;

        try {
            $this->connectToServer()->exec(sprintf('DROP DATABASE IF EXISTS `%s`', $this->schema));
        } catch (StorageException | PDOException) {
            // Nothing useful to do while tearing down; the schema name is unique
            // per run, so a leftover cannot affect a later run.
        }
    }

    public function name(): string
    {
        return $this->schema;
    }

    private function connectToServer(): PDO
    {
        return ConnectionFactory::fromDsn(
            sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $this->host, $this->port),
            $this->user,
            $this->password,
            [PDO::ATTR_TIMEOUT => self::CONNECT_TIMEOUT]
        );
    }

    private static function env(string $name): ?string
    {
        foreach ([$_ENV[$name] ?? null, $_SERVER[$name] ?? null, getenv($name)] as $value) {
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return null;
    }
}
