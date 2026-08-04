<?php

declare(strict_types=1);

namespace Diary\Tests\Unit\Storage;

use Diary\Storage\ConnectionFactory;
use Diary\Storage\StorageException;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * The factory is the only place a connection is configured, so its DSN
 * construction and its option set are worth pinning down (Requirement 4.1).
 */
final class ConnectionFactoryTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function config(array $overrides = []): array
    {
        return [
            'database' => $overrides + [
                'host' => 'localhost',
                'port' => 3306,
                'name' => 'diary',
                'user' => 'diary_user',
                'password' => 'secret',
                'charset' => 'utf8mb4',
            ],
        ];
    }

    public function testDsnUsesConfiguredHostPortDatabaseAndCharset(): void
    {
        self::assertSame(
            'mysql:host=db.example.com;port=3307;dbname=diary_live;charset=utf8mb4',
            ConnectionFactory::dsnFromConfig($this->config([
                'host' => 'db.example.com',
                'port' => 3307,
                'name' => 'diary_live',
            ]))
        );
    }

    public function testDsnAcceptsTheDatabaseSectionOnItsOwn(): void
    {
        $config = $this->config();

        self::assertSame(
            ConnectionFactory::dsnFromConfig($config),
            ConnectionFactory::dsnFromConfig($config['database'])
        );
    }

    public function testPortAndCharsetFallBackToMariaDbDefaults(): void
    {
        $config = $this->config();
        unset($config['database']['port'], $config['database']['charset']);

        self::assertSame(
            'mysql:host=localhost;port=3306;dbname=diary;charset=utf8mb4',
            ConnectionFactory::dsnFromConfig($config)
        );
    }

    public function testNumericStringPortIsAccepted(): void
    {
        self::assertStringContainsString(
            'port=3307',
            ConnectionFactory::dsnFromConfig($this->config(['port' => '3307']))
        );
    }

    public function testMissingHostIsRejected(): void
    {
        $config = $this->config();
        unset($config['database']['host']);

        $this->expectException(StorageException::class);
        $this->expectExceptionMessage('missing "host"');

        ConnectionFactory::dsnFromConfig($config);
    }

    public function testEmptyDatabaseNameIsRejected(): void
    {
        $this->expectException(StorageException::class);

        ConnectionFactory::dsnFromConfig($this->config(['name' => '']));
    }

    public function testOutOfRangePortIsRejected(): void
    {
        $this->expectException(StorageException::class);
        $this->expectExceptionMessage('port');

        ConnectionFactory::dsnFromConfig($this->config(['port' => 70000]));
    }

    public function testDefaultOptionsThrowOnErrorAndDisableEmulatedPrepares(): void
    {
        $options = ConnectionFactory::defaultOptions();

        self::assertSame(PDO::ERRMODE_EXCEPTION, $options[PDO::ATTR_ERRMODE]);
        self::assertFalse($options[PDO::ATTR_EMULATE_PREPARES]);
        self::assertFalse($options[PDO::ATTR_STRINGIFY_FETCHES]);
        self::assertSame(PDO::FETCH_ASSOC, $options[PDO::ATTR_DEFAULT_FETCH_MODE]);
    }

    public function testMysqlOnlyOptionsAreNotSentToOtherDrivers(): void
    {
        if (!defined('PDO::MYSQL_ATTR_MULTI_STATEMENTS')) {
            self::markTestSkipped('pdo_mysql is not loaded.');
        }

        self::assertArrayHasKey(PDO::MYSQL_ATTR_MULTI_STATEMENTS, ConnectionFactory::defaultOptions('mysql'));
        self::assertArrayNotHasKey(PDO::MYSQL_ATTR_MULTI_STATEMENTS, ConnectionFactory::defaultOptions('sqlite'));
    }

    public function testDriverIsReadFromTheDsnPrefix(): void
    {
        self::assertSame('mysql', ConnectionFactory::driverFromDsn('mysql:host=localhost;dbname=diary'));
        self::assertSame('sqlite', ConnectionFactory::driverFromDsn('sqlite::memory:'));
        self::assertNull(ConnectionFactory::driverFromDsn('nonsense'));
    }

    public function testAConnectionCarriesTheHardenedAttributes(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_sqlite is not loaded.');
        }

        $pdo = ConnectionFactory::fromDsn('sqlite::memory:');

        self::assertSame(PDO::ERRMODE_EXCEPTION, $pdo->getAttribute(PDO::ATTR_ERRMODE));
        self::assertSame('sqlite', $pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
    }

    public function testAnUnreachableDatabaseFailsWithoutLeakingThePassword(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_sqlite is not loaded.');
        }

        try {
            // A directory that does not exist, so the driver cannot create the file.
            ConnectionFactory::fromDsn(
                'sqlite:' . sys_get_temp_dir() . '/no-such-directory-' . bin2hex(random_bytes(6)) . '/diary.sqlite',
                'diary_user',
                'super-secret-password'
            );
            self::fail('Expected a StorageException.');
        } catch (StorageException $exception) {
            self::assertStringNotContainsString('super-secret-password', $exception->getMessage());
        }
    }

    public function testAnEmptyDsnIsRejected(): void
    {
        $this->expectException(StorageException::class);

        ConnectionFactory::fromDsn('');
    }
}
