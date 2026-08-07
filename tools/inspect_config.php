<?php

declare(strict_types=1);

/**
 * Prints the shape of a config file without printing its secrets: values that
 * are safe to see are shown, credentials and keys only as a length.
 *
 * Usage: php tools/inspect_config.php config/config.php
 */

$path = $argv[1] ?? 'config/config.php';

if (!is_file($path)) {
    echo "MISSING: {$path}\n";
    exit(1);
}

$config = require $path;

if (!is_array($config)) {
    echo "NOT AN ARRAY: {$path}\n";
    exit(1);
}

$safe = [
    'env', 'base_url', 'force_https', 'trust_forwarded_proto', 'registration_enabled',
    'host', 'port', 'name', 'charset',
    'enabled', 'provider', 'endpoint', 'model', 'timeout_seconds', 'retries',
];

echo "--- {$path} ---\n";

foreach ($config as $section => $values) {
    foreach ($values as $key => $value) {
        printf(
            "%-11s %-24s %s\n",
            $section,
            $key,
            in_array($key, $safe, true)
                ? var_export($value, true)
                : '<set: ' . strlen((string) $value) . ' chars>'
        );
    }
}
