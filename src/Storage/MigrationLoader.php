<?php

declare(strict_types=1);

namespace Diary\Storage;

/**
 * Reads the migrations directory into an ordered list.
 *
 * Ordering is numeric on the version prefix, not lexicographic, so 010 follows
 * 009 rather than sorting next to 001. Two files claiming the same version is an
 * error: it would make "apply in order" ambiguous.
 */
final class MigrationLoader
{
    /**
     * @return list<Migration> ordered by version, ascending
     */
    public static function fromDirectory(string $directory): array
    {
        if (!is_dir($directory)) {
            throw new MigrationException(sprintf('Migrations directory "%s" does not exist.', $directory));
        }

        $paths = glob(rtrim($directory, '/\\') . DIRECTORY_SEPARATOR . '*.sql');

        if ($paths === false) {
            throw new MigrationException(sprintf('Migrations directory "%s" could not be read.', $directory));
        }

        $migrations = [];

        foreach ($paths as $path) {
            $migration = Migration::fromFile($path);
            $version = $migration->version();

            if (isset($migrations[$version])) {
                throw new MigrationException(sprintf(
                    'Migrations "%s" and "%s" both claim version %d.',
                    $migrations[$version]->fileName(),
                    $migration->fileName(),
                    $version
                ));
            }

            $migrations[$version] = $migration;
        }

        ksort($migrations, SORT_NUMERIC);

        return array_values($migrations);
    }
}
