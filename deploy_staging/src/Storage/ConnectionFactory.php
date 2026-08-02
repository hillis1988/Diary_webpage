<?php

declare(strict_types=1);

namespace Diary\Storage;

use PDO;
use PDOException;

/**
 * The single place a PDO connection is created (Requirement 4.1).
 *
 * Every connection this factory hands out is configured the same way:
 *
 *   - ERRMODE_EXCEPTION, so a failed statement can never be silently ignored;
 *   - EMULATE_PREPARES off, so placeholders are bound by the server and a
 *     prepared statement is a real prepared statement rather than client-side
 *     string building. Owner scoping is a bound parameter on every query, and
 *     that guarantee is only worth something if binding is genuine;
 *   - STRINGIFY_FETCHES off, so integer columns come back as integers;
 *   - MULTI_STATEMENTS off where the driver supports it, so a single call can
 *     never be tricked into running a second statement. The migration runner
 *     splits its files and issues one statement per call, so it does not need
 *     multi-statement support.
 *
 * The DSN pins the connection charset to utf8mb4 so emoji in free-text answers
 * survive a round trip.
 */
final class ConnectionFactory
{
    private const DEFAULT_PORT = 3306;
    private const DEFAULT_CHARSET = 'utf8mb4';

    /**
     * @param array<string, mixed> $config the whole configuration array, or just its 'database' section
     * @param array<int, mixed>    $extraOptions PDO options merged over the defaults
     */
    public static function fromConfig(array $config, array $extraOptions = []): PDO
    {
        $database = self::databaseSection($config);

        return self::fromDsn(
            self::dsnFromConfig($config),
            self::requireString($database, 'user'),
            self::requireString($database, 'password', allowEmpty: true),
            $extraOptions
        );
    }

    /**
     * Driver-agnostic entry point. Integration tests point this at a throwaway
     * MariaDB schema, and unit tests can point it at SQLite, without either
     * having to remember the option set above.
     *
     * @param array<int, mixed> $extraOptions
     */
    public static function fromDsn(
        string $dsn,
        ?string $user = null,
        ?string $password = null,
        array $extraOptions = []
    ): PDO {
        if ($dsn === '') {
            throw new StorageException('A database DSN is required.');
        }

        $options = $extraOptions + self::defaultOptions(self::driverFromDsn($dsn));

        try {
            return new PDO($dsn, $user, $password, $options);
        } catch (PDOException $exception) {
            // The DSN is safe to quote (it holds no password); the exception
            // message from the driver is not repeated verbatim for the same
            // reason, but its code is useful when diagnosing a refused connection.
            throw new StorageException(
                sprintf('Could not connect to the database using "%s".', self::redactDsn($dsn)),
                0,
                $exception
            );
        }
    }

    /**
     * @param array<string, mixed> $config
     */
    public static function dsnFromConfig(array $config): string
    {
        $database = self::databaseSection($config);

        $host = self::requireString($database, 'host');
        $name = self::requireString($database, 'name');
        $port = self::port($database);
        $charset = isset($database['charset']) && $database['charset'] !== ''
            ? self::requireString($database, 'charset')
            : self::DEFAULT_CHARSET;

        return sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $host, $port, $name, $charset);
    }

    /**
     * The hardened defaults, exposed so tests and other drivers can assert or reuse them.
     *
     * Driver-specific attributes share a numeric range across drivers, so the
     * MySQL-only attribute is added for the MySQL/MariaDB driver and nothing else.
     *
     * @return array<int, mixed>
     */
    public static function defaultOptions(?string $driver = 'mysql'): array
    {
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
        ];

        // The constant only exists when pdo_mysql is loaded; the factory still works without it.
        if ($driver === 'mysql' && defined('PDO::MYSQL_ATTR_MULTI_STATEMENTS')) {
            $options[PDO::MYSQL_ATTR_MULTI_STATEMENTS] = false;
        }

        return $options;
    }

    public static function driverFromDsn(string $dsn): ?string
    {
        $colon = strpos($dsn, ':');

        return $colon === false || $colon === 0 ? null : strtolower(substr($dsn, 0, $colon));
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return array<string, mixed>
     */
    private static function databaseSection(array $config): array
    {
        $section = array_key_exists('database', $config) ? $config['database'] : $config;

        if (!is_array($section)) {
            throw new StorageException('The "database" configuration section must be an array.');
        }

        /** @var array<string, mixed> $section */
        return $section;
    }

    /**
     * @param array<string, mixed> $database
     */
    private static function requireString(array $database, string $key, bool $allowEmpty = false): string
    {
        if (!array_key_exists($key, $database)) {
            throw new StorageException(sprintf('Database configuration is missing "%s".', $key));
        }

        $value = $database[$key];

        if (!is_string($value)) {
            throw new StorageException(sprintf('Database configuration "%s" must be a string.', $key));
        }

        if (!$allowEmpty && $value === '') {
            throw new StorageException(sprintf('Database configuration "%s" must not be empty.', $key));
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $database
     */
    private static function port(array $database): int
    {
        if (!array_key_exists('port', $database) || $database['port'] === '' || $database['port'] === null) {
            return self::DEFAULT_PORT;
        }

        $port = $database['port'];

        if (is_string($port) && preg_match('/^\d+$/', $port) === 1) {
            $port = (int) $port;
        }

        if (!is_int($port) || $port < 1 || $port > 65535) {
            throw new StorageException('Database configuration "port" must be a TCP port between 1 and 65535.');
        }

        return $port;
    }

    private static function redactDsn(string $dsn): string
    {
        // Defensive: a DSN should never carry credentials, but if someone puts a
        // password in one, it does not end up in a log or an error page.
        return (string) preg_replace('/(password|pwd)=[^;]*/i', '$1=***', $dsn);
    }
}
