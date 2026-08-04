<?php

declare(strict_types=1);

namespace Diary\Storage;

/**
 * One migration file: `NNN_snake_case_name.sql` under migrations/.
 *
 * The version is the numeric prefix and is the ordering key and the primary key
 * of the bookkeeping table. The checksum is a SHA-256 of the file contents, so
 * an already-applied file that has since been edited can be reported instead of
 * silently diverging from the live schema.
 */
final class Migration
{
    private function __construct(
        private readonly int $version,
        private readonly string $name,
        private readonly string $path,
        private readonly string $sql,
    ) {
    }

    public static function fromFile(string $path): self
    {
        $fileName = basename($path);

        if (preg_match('/^(\d+)_([A-Za-z0-9_\-]+)\.sql$/', $fileName, $matches) !== 1) {
            throw new MigrationException(sprintf(
                'Migration file "%s" must be named NNN_name.sql.',
                $fileName
            ));
        }

        $sql = @file_get_contents($path);

        if ($sql === false) {
            throw new MigrationException(sprintf('Migration file "%s" could not be read.', $fileName));
        }

        return new self((int) $matches[1], $matches[2], $path, $sql);
    }

    /**
     * Only used by tests that need a migration without a file on disk.
     */
    public static function fromSql(int $version, string $name, string $sql): self
    {
        return new self($version, $name, sprintf('%03d_%s.sql', $version, $name), $sql);
    }

    public function version(): int
    {
        return $this->version;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function path(): string
    {
        return $this->path;
    }

    public function fileName(): string
    {
        return basename($this->path);
    }

    public function sql(): string
    {
        return $this->sql;
    }

    public function checksum(): string
    {
        // Normalise line endings so a checkout with CRLF endings does not look
        // like an edited migration.
        return hash('sha256', str_replace("\r\n", "\n", $this->sql));
    }

    /**
     * @return list<string>
     */
    public function statements(): array
    {
        $statements = SqlStatementSplitter::split($this->sql);

        if ($statements === []) {
            throw new MigrationException(sprintf(
                'Migration "%s" contains no SQL statements.',
                $this->fileName()
            ));
        }

        return $statements;
    }
}
