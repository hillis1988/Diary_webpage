<?php

declare(strict_types=1);

/**
 * Seeds a throwaway owner account for local visual browsing.
 *
 *   php tools/seed_local_user.php
 */

use Diary\Auth\AuthService;
use Diary\Auth\AuditLogRepository;
use Diary\Auth\DefaultPasswordPolicy;
use Diary\Auth\IpHasher;
use Diary\Auth\SessionRepository;
use Diary\Auth\UserRepository;
use Diary\Storage\ConnectionFactory;
use Diary\Support\SystemClock;

require __DIR__ . '/../vendor/autoload.php';

$config = require __DIR__ . '/../config/config.php';
$pdo = ConnectionFactory::fromConfig($config);
$clock = new SystemClock();

$masterKey = base64_decode((string) $config['encryption']['master_key_base64'], true);
if ($masterKey === false || strlen($masterKey) !== 32) {
    fwrite(STDERR, "Local config master key is invalid.\n");
    exit(1);
}

$email = 'local@preview.test';
$password = 'LocalPreview1!';

$auth = new AuthService(
    users: new UserRepository($pdo),
    passwordPolicy: new DefaultPasswordPolicy(),
    clock: $clock,
    sessions: new SessionRepository($pdo),
    auditLog: new AuditLogRepository($pdo),
    ipHasher: new IpHasher(hash_hkdf('sha256', $masterKey, 32, 'diary-ip-hash-v1')),
);

$result = $auth->register($email, $password);

if ($result->isOk()) {
    fwrite(STDOUT, "Created local owner account.\n");
} else {
    fwrite(STDOUT, 'Register note: ' . $result->message() . "\n");
}

$login = $auth->authenticate($email, $password, $clock->now(), '127.0.0.1');
if (!$login->isOk()) {
    fwrite(STDERR, 'Could not verify login: ' . $login->message() . "\n");
    exit(1);
}

fwrite(STDOUT, "Login works.\n");
fwrite(STDOUT, "Email:    {$email}\n");
fwrite(STDOUT, "Password: {$password}\n");
fwrite(STDOUT, "Open:     http://127.0.0.1:8080/login\n");
