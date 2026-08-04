<?php

declare(strict_types=1);

namespace Diary\Tests\Unit\Storage;

use Diary\Storage\Migration;
use Diary\Storage\MigrationException;
use Diary\Storage\MigrationLoader;
use PHPUnit\Framework\TestCase;

/**
 * "Apply in order" only means something if the order is numeric and unambiguous.
 */
final class MigrationLoaderTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/diary-migrations-' . bin2hex(random_bytes(6));
        mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        // scandir rather than glob, so dotfiles written by a test are removed too.
        foreach (array_diff(scandir($this->directory) ?: [], ['.', '..']) as $entry) {
            unlink($this->directory . '/' . $entry);
        }

        if (is_dir($this->directory)) {
            rmdir($this->directory);
        }
    }

    private function write(string $fileName, string $sql = 'SELECT 1;'): void
    {
        file_put_contents($this->directory . '/' . $fileName, $sql);
    }

    public function testMigrationsAreOrderedNumericallyNotLexicographically(): void
    {
        $this->write('010_tenth.sql');
        $this->write('002_second.sql');
        $this->write('001_first.sql');

        $versions = array_map(
            static fn (Migration $migration): int => $migration->version(),
            MigrationLoader::fromDirectory($this->directory)
        );

        self::assertSame([1, 2, 10], $versions);
    }

    public function testNonSqlFilesAreIgnored(): void
    {
        $this->write('001_first.sql');
        file_put_contents($this->directory . '/.gitkeep', '');
        file_put_contents($this->directory . '/README.md', 'notes');

        self::assertCount(1, MigrationLoader::fromDirectory($this->directory));
    }

    public function testTheNameIsTakenFromTheFileName(): void
    {
        $this->write('003_create_encryption_keys.sql');

        $migrations = MigrationLoader::fromDirectory($this->directory);

        self::assertSame('create_encryption_keys', $migrations[0]->name());
        self::assertSame('003_create_encryption_keys.sql', $migrations[0]->fileName());
    }

    public function testTwoFilesClaimingTheSameVersionAreRejected(): void
    {
        $this->write('004_one_way.sql');
        $this->write('004_another_way.sql');

        $this->expectException(MigrationException::class);
        $this->expectExceptionMessage('version 4');

        MigrationLoader::fromDirectory($this->directory);
    }

    public function testAMisnamedFileIsRejected(): void
    {
        $this->write('create_users.sql');

        $this->expectException(MigrationException::class);
        $this->expectExceptionMessage('NNN_name.sql');

        MigrationLoader::fromDirectory($this->directory);
    }

    public function testAMissingDirectoryIsRejected(): void
    {
        $this->expectException(MigrationException::class);

        MigrationLoader::fromDirectory($this->directory . '/nope');
    }

    public function testAnEmptyDirectoryLoadsNothing(): void
    {
        self::assertSame([], MigrationLoader::fromDirectory($this->directory));
    }

    public function testChecksumIgnoresLineEndingStyle(): void
    {
        $this->write('001_first.sql', "SELECT 1;\nSELECT 2;\n");
        $lf = MigrationLoader::fromDirectory($this->directory)[0]->checksum();

        $this->write('001_first.sql', "SELECT 1;\r\nSELECT 2;\r\n");
        $crlf = MigrationLoader::fromDirectory($this->directory)[0]->checksum();

        self::assertSame($lf, $crlf);
    }

    public function testTheProjectMigrationsLoadInOrderStartingAtOne(): void
    {
        $migrations = MigrationLoader::fromDirectory(__DIR__ . '/../../../migrations');

        self::assertNotEmpty($migrations);

        $expected = 1;

        foreach ($migrations as $migration) {
            self::assertSame($expected, $migration->version(), 'migration versions must have no gaps');
            $expected++;
        }
    }
}
