<?php

declare(strict_types=1);

/**
 * Front-controller router for PHP's built-in development server.
 *
 * Usage:
 *   php -S 127.0.0.1:8080 -t public public/router.php
 */

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$file = __DIR__ . $path;

if ($path !== '/' && is_string($path) && $path !== '' && is_file($file)) {
    return false;
}

require __DIR__ . '/index.php';
