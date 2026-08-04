<?php

declare(strict_types=1);

/**
 * Apply pending database migrations.
 *
 *   php tools/migrate.php [--config=PATH] [--migrations=PATH] [--table=NAME] [--pending] [--quiet]
 *
 *   --config      configuration file to read database credentials from
 *                 (default: config/config.php)
 *   --migrations  directory holding NNN_name.sql files (default: migrations/)
 *   --table       bookkeeping table name (default: schema_migrations)
 *   --pending     list what would be applied and exit without touching the schema
 *   --quiet       print nothing on success
 *
 * Re-running is safe: versions already recorded in the bookkeeping table are
 * skipped, so a second run reports "already up to date" and changes nothing
 * (Requirement 4.1).
 *
 * Exit codes: 0 success, 1 failure.
 *
 * This is a thin entry point. All of the behaviour lives in src/Storage/ so it
 * can be tested without a shell.
 */

use Diary\Storage\ConnectionFactory;
use Diary\Storage\MigrationLoader;
use Diary\Storage\MigrationRunner;
use Diary\Storage\StorageException;
use Diary\Support\SystemClock;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

require __DIR__ . '/../vendor/autoload.php';

$root = dirname(__DIR__);

/**
 * @param list<string> $argv
 *
 * @return array<string, string|bool>
 */
$parseArguments = static function (array $argv): array {
    $options = [];

    foreach (array_slice($argv, 1) as $argument) {
        if (preg_match('/^--([A-Za-z\-]+)(?:=(.*))?$/', $argument, $matches) !== 1) {
            fwrite(STDERR, sprintf('Unrecognised argument "%s".%s', $argument, PHP_EOL));
            exit(1);
        }

        $options[$matches[1]] = $matches[2] ?? true;
    }

    return $options;
};

$options = $parseArguments($argv);
$quiet = isset($options['quiet']);

$say = static function (string $line) use ($quiet): void {
    if (!$quiet) {
        fwrite(STDOUT, $line . PHP_EOL);
    }
};

$configPath = is_string($options['config'] ?? null) ? $options['config'] : $root . '/config/config.php';
$migrationsPath = is_string($options['migrations'] ?? null) ? $options['migrations'] : $root . '/migrations';
$table = is_string($options['table'] ?? null) ? $options['table'] : 'schema_migrations';

try {
    if (!is_file($configPath)) {
        throw new StorageException(sprintf(
            'Configuration file "%s" not found. Copy config/config.example.php to config/config.php and fill it in.',
            $configPath
        ));
    }

    $config = require $configPath;

    if (!is_array($config)) {
        throw new StorageException(sprintf('Configuration file "%s" must return an array.', $configPath));
    }

    $migrations = MigrationLoader::fromDirectory($migrationsPath);
    $pdo = ConnectionFactory::fromConfig($config);
    $runner = new MigrationRunner($pdo, new SystemClock(), $table);

    if (isset($options['pending'])) {
        $pending = $runner->pending($migrations);

        if ($pending === []) {
            $say('Schema is already up to date.');
            exit(0);
        }

        $say(sprintf('%d migration(s) pending:', count($pending)));

        foreach ($pending as $migration) {
            $say('  ' . $migration->fileName());
        }

        exit(0);
    }

    $report = $runner->migrate($migrations);

    foreach ($report->recordedButMissing() as $version) {
        fwrite(STDERR, sprintf(
            'Warning: version %d is recorded as applied but has no file in %s.%s',
            $version,
            $migrationsPath,
            PHP_EOL
        ));
    }

    if ($report->wasNoOp()) {
        $say(sprintf(
            'Schema is already up to date (%d migration(s) recorded).',
            count($report->alreadyApplied())
        ));
        exit(0);
    }

    foreach ($report->applied() as $migration) {
        $say('Applied ' . $migration->fileName());
    }

    $say(sprintf('Done: %d applied, %d already present.', count($report->applied()), count($report->alreadyApplied())));
    exit(0);
} catch (StorageException $failure) {
    fwrite(STDERR, 'Migration failed: ' . $failure->getMessage() . PHP_EOL);
    exit(1);
} catch (Throwable $failure) {
    fwrite(STDERR, 'Migration failed: ' . $failure->getMessage() . PHP_EOL);
    exit(1);
}
