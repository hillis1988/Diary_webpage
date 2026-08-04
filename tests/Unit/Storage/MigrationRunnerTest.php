<?php

declare(strict_types=1);

namespace Diary\Tests\Unit\Storage;

use Diary\Storage\ConnectionFactory;
use Diary\Storage\Migration;
use Diary\Storage\MigrationException;
use Diary\Storage\MigrationRunner;
use Diary\Support\FixedClock;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Runner behaviour, exercised against an in-memory database so it needs no server.
 *
 * The migrations here are deliberately plain SQL rather than the project's
 * MariaDB files: what is under test is the ordering, bookkeeping and
 * re-runnability of the runner (Requirement 4.1). Applying the real MariaDB
 * migrations is the integration test's job.
 */
final class MigrationRunnerTest extends TestCase
{
    private PDO $pdo;
    private FixedClock $clock;

    protected function setUp(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_sqlite is not loaded.');
        }

        $this->pdo = ConnectionFactory::fromDsn('sqlite::memory:');
        $this->clock = FixedClock::at('2025-03-01 09:30:00');
    }

    private function runner(string $table = 'schema_migrations'): MigrationRunner
    {
        return new MigrationRunner($this->pdo, $this->clock, $table);
    }

    /**
     * @return list<Migration>
     */
    private function migrations(): array
    {
        return [
            Migration::fromSql(1, 'create_notes', "-- notes\nCREATE TABLE notes (id INTEGER PRIMARY KEY);"),
            Migration::fromSql(2, 'create_tags', 'CREATE TABLE tags (id INTEGER PRIMARY KEY);'),
        ];
    }

    /**
     * @return list<string>
     */
    private function tableNames(): array
    {
        $rows = $this->pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' ORDER BY name");
        self::assertNotFalse($rows);

        return array_map(static fn (array $row): string => (string) $row['name'], $rows->fetchAll(PDO::FETCH_ASSOC));
    }

    public function testAllMigrationsApplyToAnEmptyDatabase(): void
    {
        $report = $this->runner()->migrate($this->migrations());

        self::assertSame([1, 2], $report->appliedVersions());
        self::assertSame([], $report->alreadyAppliedVersions());
        self::assertFalse($report->wasNoOp());
        self::assertSame(['notes', 'schema_migrations', 'tags'], $this->tableNames());
    }

    public function testRerunningAppliesNothingAndReportsANoOp(): void
    {
        $migrations = $this->migrations();
        $this->runner()->migrate($migrations);

        $second = $this->runner()->migrate($migrations);

        self::assertTrue($second->wasNoOp());
        self::assertSame([], $second->appliedVersions());
        self::assertSame([1, 2], $second->alreadyAppliedVersions());
    }

    public function testOnlyPendingMigrationsAreApplied(): void
    {
        $migrations = $this->migrations();
        $this->runner()->migrate([$migrations[0]]);

        $report = $this->runner()->migrate($migrations);

        self::assertSame([2], $report->appliedVersions());
        self::assertSame([1], $report->alreadyAppliedVersions());
    }

    public function testPendingListsWhatARunWouldApplyWithoutApplyingIt(): void
    {
        $runner = $this->runner();
        $migrations = $this->migrations();

        $pending = $runner->pending($migrations);

        self::assertSame([1, 2], array_map(static fn (Migration $m): int => $m->version(), $pending));
        self::assertNotContains('notes', $this->tableNames());

        $runner->migrate($migrations);

        self::assertSame([], $runner->pending($migrations));
    }

    public function testAppliedVersionsRecordNameChecksumAndTimeFromTheClock(): void
    {
        $migrations = $this->migrations();
        $this->runner()->migrate($migrations);

        $recorded = $this->runner()->appliedVersions();

        self::assertSame([1, 2], array_keys($recorded));
        self::assertSame('create_notes', $recorded[1]['name']);
        self::assertSame($migrations[0]->checksum(), $recorded[1]['checksum']);
        self::assertSame('2025-03-01 09:30:00', $recorded[1]['applied_at']);
    }

    public function testEditingAnAppliedMigrationIsReportedRatherThanIgnored(): void
    {
        $this->runner()->migrate($this->migrations());

        $edited = [
            Migration::fromSql(1, 'create_notes', 'CREATE TABLE notes (id INTEGER PRIMARY KEY, extra TEXT);'),
            $this->migrations()[1],
        ];

        $this->expectException(MigrationException::class);
        $this->expectExceptionMessage('contents have changed');

        $this->runner()->migrate($edited);
    }

    public function testAFailingMigrationStopsTheRunAndIsNotRecorded(): void
    {
        $migrations = [
            $this->migrations()[0],
            Migration::fromSql(2, 'broken', 'THIS IS NOT SQL;'),
            Migration::fromSql(3, 'create_tags', 'CREATE TABLE tags (id INTEGER PRIMARY KEY);'),
        ];

        try {
            $this->runner()->migrate($migrations);
            self::fail('Expected a MigrationException.');
        } catch (MigrationException $exception) {
            self::assertStringContainsString('002_broken.sql', $exception->getMessage());
        }

        self::assertSame([1], array_keys($this->runner()->appliedVersions()));
        self::assertNotContains('tags', $this->tableNames());
    }

    public function testRecoveringAfterAFailureAppliesTheRemainingMigrations(): void
    {
        $broken = [
            $this->migrations()[0],
            Migration::fromSql(2, 'broken', 'THIS IS NOT SQL;'),
        ];

        try {
            $this->runner()->migrate($broken);
        } catch (MigrationException) {
            // expected
        }

        $fixed = [
            $this->migrations()[0],
            Migration::fromSql(2, 'broken', 'CREATE TABLE fixed (id INTEGER PRIMARY KEY);'),
        ];

        $report = $this->runner()->migrate($fixed);

        self::assertSame([2], $report->appliedVersions());
        self::assertContains('fixed', $this->tableNames());
    }

    public function testAVersionRecordedWithNoFileOnDiskIsReported(): void
    {
        $this->runner()->migrate($this->migrations());

        $report = $this->runner()->migrate([$this->migrations()[0]]);

        self::assertSame([2], $report->recordedButMissing());
    }

    public function testEnsureBookkeepingTableIsIdempotent(): void
    {
        $runner = $this->runner();
        $runner->ensureBookkeepingTable();
        $runner->ensureBookkeepingTable();

        self::assertSame(['schema_migrations'], $this->tableNames());
        self::assertSame([], $runner->appliedVersions());
    }

    public function testTheBookkeepingTableNameIsConfigurableAndValidated(): void
    {
        $this->runner('diary_schema_version')->migrate($this->migrations());

        self::assertContains('diary_schema_version', $this->tableNames());

        $this->expectException(MigrationException::class);
        $this->expectExceptionMessage('not a usable table name');

        $this->runner('schema migrations; DROP TABLE notes');
    }

    public function testAMigrationWithNoStatementsIsRejected(): void
    {
        $this->expectException(MigrationException::class);
        $this->expectExceptionMessage('no SQL statements');

        $this->runner()->migrate([Migration::fromSql(1, 'empty', "-- nothing to do\n")]);
    }
}
