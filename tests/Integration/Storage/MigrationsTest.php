<?php

declare(strict_types=1);

namespace Diary\Tests\Integration\Storage;

use Diary\Storage\MigrationLoader;
use Diary\Storage\MigrationRunner;
use Diary\Support\FixedClock;
use Diary\Tests\Integration\MariaDbTestSchema;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * The real MariaDB migrations applied to a real, empty MariaDB schema
 * (Requirement 4.1).
 *
 * The unit tests cover the runner's ordering and bookkeeping against SQLite;
 * what only a server can answer is whether migrations/001..008 are valid MariaDB
 * SQL, whether they apply in order given their foreign keys, whether the tables
 * and indexes the design specifies actually exist afterwards, and whether a
 * second run is genuinely a no-op.
 *
 * Each test gets a freshly created throwaway schema and drops it afterwards.
 * With no server reachable the whole class skips with a message explaining how
 * to run it.
 */
final class MigrationsTest extends TestCase
{
    /** Every table the schema must have after a full migration run. */
    private const EXPECTED_TABLES = [
        'audit_log',
        'cbt_recommendations',
        'diary_entries',
        'encryption_keys',
        'milestones',
        'purge_jobs',
        'schema_migrations',
        'sessions',
        'users',
    ];

    /**
     * The indexes the design leans on, as table => index => [unique, columns].
     *
     * These are the ones with behaviour attached: the uniqueness rules the
     * services rely on to make an upsert safe, and the lookup indexes the
     * calendar and range reads use.
     *
     * @var array<string, array<string, array{bool, list<string>}>>
     */
    private const EXPECTED_INDEXES = [
        'users' => [
            'PRIMARY' => [true, ['id']],
            'uq_users_email_normalized' => [true, ['email_normalized']],
            'idx_users_data_owner' => [false, ['data_owner_id']],
            'idx_users_deletion_requested_at' => [false, ['deletion_requested_at']],
        ],
        'sessions' => [
            'PRIMARY' => [true, ['id']],
            'idx_sessions_expiry' => [false, ['terminated_at', 'last_activity_at']],
        ],
        'encryption_keys' => [
            'PRIMARY' => [true, ['id']],
            'idx_encryption_keys_active' => [false, ['retired_at', 'created_at']],
        ],
        'diary_entries' => [
            'PRIMARY' => [true, ['id']],
            'uq_diary_entries_owner_date' => [true, ['owner_id', 'entry_date']],
        ],
        'cbt_recommendations' => [
            'PRIMARY' => [true, ['id']],
            'uq_cbt_recommendations_entry' => [true, ['entry_id']],
        ],
        'milestones' => [
            'PRIMARY' => [true, ['id']],
            'idx_milestones_milestone_date' => [false, ['milestone_date']],
            'idx_milestones_owner_date' => [false, ['owner_id', 'milestone_date']],
        ],
        'purge_jobs' => [
            'PRIMARY' => [true, ['id']],
            'idx_purge_jobs_outstanding' => [false, ['completed_at', 'requested_at']],
        ],
        'audit_log' => [
            'PRIMARY' => [true, ['id']],
            'idx_audit_log_occurred_at' => [false, ['occurred_at']],
        ],
    ];

    private MariaDbTestSchema $schema;
    private PDO $pdo;

    protected function setUp(): void
    {
        $reason = MariaDbTestSchema::unavailableReason();

        if ($reason !== null) {
            self::markTestSkipped($reason);
        }

        $schema = MariaDbTestSchema::fromEnvironment();
        self::assertNotNull($schema);

        $this->schema = $schema;
        $this->pdo = $schema->create();
    }

    protected function tearDown(): void
    {
        if (isset($this->schema)) {
            $this->schema->drop();
        }
    }

    public function testEveryMigrationAppliesToAnEmptySchema(): void
    {
        self::assertSame([], $this->tableNames(), 'The throwaway schema should start empty.');

        $migrations = MigrationLoader::fromDirectory($this->migrationsDirectory());
        $report = $this->runner()->migrate($migrations);

        self::assertSame([1, 2, 3, 4, 5, 6, 7, 8], $report->appliedVersions());
        self::assertSame([], $report->alreadyAppliedVersions());
        self::assertSame([], $report->recordedButMissing());
        self::assertFalse($report->wasNoOp());
        self::assertSame(self::EXPECTED_TABLES, $this->tableNames());
    }

    public function testTheExpectedIndexesExistAfterMigrating(): void
    {
        $this->runner()->migrateDirectory($this->migrationsDirectory());

        foreach (self::EXPECTED_INDEXES as $table => $expected) {
            $actual = $this->indexes($table);

            foreach ($expected as $name => [$unique, $columns]) {
                self::assertArrayHasKey($name, $actual, sprintf('%s is missing index %s.', $table, $name));
                self::assertSame(
                    ['unique' => $unique, 'columns' => $columns],
                    $actual[$name],
                    sprintf('Index %s on %s does not match the design.', $name, $table)
                );
            }
        }
    }

    public function testASecondRunAppliesNothingAndLeavesTheSchemaUntouched(): void
    {
        $migrations = MigrationLoader::fromDirectory($this->migrationsDirectory());

        $first = $this->runner('2025-03-01 09:30:00')->migrate($migrations);
        $afterFirst = $this->structureSnapshot();
        $recordedAfterFirst = $this->runner()->appliedVersions();

        // A later clock would show up in applied_at if the runner re-applied anything.
        $second = $this->runner('2025-06-15 12:00:00')->migrate($migrations);

        self::assertTrue($second->wasNoOp());
        self::assertSame([], $second->appliedVersions());
        self::assertSame($first->appliedVersions(), $second->alreadyAppliedVersions());
        self::assertSame([], $second->recordedButMissing());
        self::assertSame($afterFirst, $this->structureSnapshot());
        self::assertSame($recordedAfterFirst, $this->runner()->appliedVersions());
    }

    public function testTheBookkeepingTableRecordsEveryMigrationFile(): void
    {
        $migrations = MigrationLoader::fromDirectory($this->migrationsDirectory());
        $this->runner('2025-03-01 09:30:00')->migrate($migrations);

        $recorded = $this->runner()->appliedVersions();

        self::assertCount(count($migrations), $recorded);

        foreach ($migrations as $migration) {
            $version = $migration->version();

            self::assertArrayHasKey($version, $recorded);
            self::assertSame($migration->name(), $recorded[$version]['name']);
            self::assertSame($migration->checksum(), $recorded[$version]['checksum']);
            self::assertSame('2025-03-01 09:30:00', $recorded[$version]['applied_at']);
        }
    }

    private function runner(string $now = '2025-03-01 09:30:00'): MigrationRunner
    {
        return new MigrationRunner($this->pdo, FixedClock::at($now));
    }

    private function migrationsDirectory(): string
    {
        return dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'migrations';
    }

    /**
     * @return list<string>
     */
    private function tableNames(): array
    {
        $statement = $this->pdo->prepare(
            'SELECT table_name AS name FROM information_schema.tables'
            . ' WHERE table_schema = :schema ORDER BY table_name'
        );
        $statement->execute([':schema' => $this->schema->name()]);

        return array_map(
            static fn (array $row): string => (string) $row['name'],
            $statement->fetchAll(PDO::FETCH_ASSOC)
        );
    }

    /**
     * @return array<string, array{unique: bool, columns: list<string>}>
     */
    private function indexes(string $table): array
    {
        $statement = $this->pdo->prepare(
            'SELECT index_name AS name, non_unique AS non_unique, column_name AS column_name'
            . ' FROM information_schema.statistics'
            . ' WHERE table_schema = :schema AND table_name = :table'
            . ' ORDER BY index_name, seq_in_index'
        );
        $statement->execute([':schema' => $this->schema->name(), ':table' => $table]);

        $indexes = [];

        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $name = (string) $row['name'];
            $indexes[$name] ??= ['unique' => (int) $row['non_unique'] === 0, 'columns' => []];
            $indexes[$name]['columns'][] = (string) $row['column_name'];
        }

        return $indexes;
    }

    /**
     * Tables and their indexes, so "the second run changed nothing" is an
     * assertion about the schema rather than only about the report.
     *
     * @return array<string, array<string, array{unique: bool, columns: list<string>}>>
     */
    private function structureSnapshot(): array
    {
        $snapshot = [];

        foreach ($this->tableNames() as $table) {
            $snapshot[$table] = $this->indexes($table);
        }

        return $snapshot;
    }
}
