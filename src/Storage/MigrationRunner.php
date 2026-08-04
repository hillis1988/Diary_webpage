<?php

declare(strict_types=1);

namespace Diary\Storage;

use Diary\Support\Clock;
use PDO;
use Throwable;

/**
 * Applies pending migrations in version order and records what it applied
 * (Requirement 4.1).
 *
 * Bookkeeping lives in a table the runner owns and creates on first use, keyed
 * on the migration version. A version present in that table is never applied
 * again, which is what makes `php tools/migrate.php` safe to re-run: a second
 * run with nothing pending touches no schema and reports a no-op.
 *
 * Portability: the bookkeeping DDL and every statement the runner issues are
 * plain SQL with no storage-engine or dialect clauses, and statements are sent
 * one at a time, so the runner works on MariaDB in production and on whatever
 * driver an integration test has available. The migration files themselves stay
 * MariaDB SQL and are never rewritten.
 *
 * Atomicity: MariaDB commits implicitly on DDL, so a migration file is the unit
 * of atomicity rather than the whole run, and a file that fails halfway leaves
 * its version unrecorded. On drivers with transactional DDL the runner wraps
 * each file in a transaction so that granularity is exact. Either way the run
 * stops at the first failure, so later migrations never apply over a broken one.
 */
final class MigrationRunner
{
    /** Drivers that can roll back schema changes; on the rest, DDL commits implicitly. */
    private const TRANSACTIONAL_DDL_DRIVERS = ['sqlite', 'pgsql'];

    public function __construct(
        private readonly PDO $pdo,
        private readonly Clock $clock,
        private readonly string $table = 'schema_migrations',
    ) {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $this->table) !== 1) {
            // The table name is interpolated into SQL because a placeholder cannot
            // stand in for an identifier, so it is validated instead of bound.
            throw new MigrationException(sprintf('"%s" is not a usable table name.', $this->table));
        }
    }

    /**
     * Load the directory and apply whatever is pending.
     */
    public function migrateDirectory(string $directory): MigrationReport
    {
        return $this->migrate(MigrationLoader::fromDirectory($directory));
    }

    /**
     * @param list<Migration> $migrations expected in version order, as MigrationLoader returns them
     */
    public function migrate(array $migrations): MigrationReport
    {
        $this->ensureBookkeepingTable();

        $recorded = $this->appliedVersions();
        $applied = [];
        $alreadyApplied = [];

        foreach ($migrations as $migration) {
            $version = $migration->version();

            if (isset($recorded[$version])) {
                $this->assertUnchanged($migration, $recorded[$version]);
                $alreadyApplied[] = $migration;
                continue;
            }

            $this->apply($migration);
            $applied[] = $migration;
        }

        $onDisk = array_map(static fn (Migration $migration): int => $migration->version(), $migrations);

        return new MigrationReport(
            $applied,
            $alreadyApplied,
            array_values(array_diff(array_keys($recorded), $onDisk))
        );
    }

    /**
     * Create the bookkeeping table if this is a fresh database. Idempotent.
     */
    public function ensureBookkeepingTable(): void
    {
        $this->pdo->exec(sprintf(
            'CREATE TABLE IF NOT EXISTS %s ('
            . ' version INTEGER NOT NULL,'
            . ' name VARCHAR(191) NOT NULL,'
            . ' checksum CHAR(64) NOT NULL,'
            . ' applied_at DATETIME NOT NULL,'
            . ' PRIMARY KEY (version)'
            . ')',
            $this->table
        ));
    }

    /**
     * @return array<int, array{name: string, checksum: string, applied_at: string}>
     */
    public function appliedVersions(): array
    {
        $statement = $this->pdo->query(sprintf(
            'SELECT version, name, checksum, applied_at FROM %s ORDER BY version',
            $this->table
        ));

        if ($statement === false) {
            throw new MigrationException('Could not read the applied migration versions.');
        }

        $recorded = [];

        /** @var array{version: int|string, name: string, checksum: string, applied_at: string} $row */
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $recorded[(int) $row['version']] = [
                'name' => (string) $row['name'],
                'checksum' => (string) $row['checksum'],
                'applied_at' => (string) $row['applied_at'],
            ];
        }

        return $recorded;
    }

    /**
     * The migrations that a run would apply right now.
     *
     * @param list<Migration> $migrations
     *
     * @return list<Migration>
     */
    public function pending(array $migrations): array
    {
        $this->ensureBookkeepingTable();
        $recorded = $this->appliedVersions();

        return array_values(array_filter(
            $migrations,
            static fn (Migration $migration): bool => !isset($recorded[$migration->version()])
        ));
    }

    private function apply(Migration $migration): void
    {
        $useTransaction = in_array(
            (string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME),
            self::TRANSACTIONAL_DDL_DRIVERS,
            true
        ) && !$this->pdo->inTransaction();

        if ($useTransaction) {
            $this->pdo->beginTransaction();
        }

        try {
            foreach ($migration->statements() as $statement) {
                $this->pdo->exec($statement);
            }

            $this->record($migration);

            if ($useTransaction) {
                $this->pdo->commit();
            }
        } catch (Throwable $failure) {
            if ($useTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw new MigrationException(
                sprintf('Migration "%s" failed: %s', $migration->fileName(), $failure->getMessage()),
                0,
                $failure
            );
        }
    }

    private function record(Migration $migration): void
    {
        $insert = $this->pdo->prepare(sprintf(
            'INSERT INTO %s (version, name, checksum, applied_at) VALUES (:version, :name, :checksum, :applied_at)',
            $this->table
        ));

        $insert->execute([
            ':version' => $migration->version(),
            ':name' => $migration->name(),
            ':checksum' => $migration->checksum(),
            ':applied_at' => $this->clock->now()->format('Y-m-d H:i:s'),
        ]);
    }

    /**
     * @param array{name: string, checksum: string, applied_at: string} $recorded
     */
    private function assertUnchanged(Migration $migration, array $recorded): void
    {
        if ($recorded['checksum'] === $migration->checksum()) {
            return;
        }

        throw new MigrationException(sprintf(
            'Migration "%s" was applied on %s but its contents have changed since. '
            . 'Add a new migration instead of editing an applied one.',
            $migration->fileName(),
            $recorded['applied_at']
        ));
    }
}
