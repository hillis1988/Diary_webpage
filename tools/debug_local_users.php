<?php

declare(strict_types=1);

use Diary\Auth\UserRepository;
use Diary\Storage\ConnectionFactory;

require __DIR__ . '/../vendor/autoload.php';

$config = require __DIR__ . '/../config/config.php';
$pdo = ConnectionFactory::fromConfig($config);
$users = new UserRepository($pdo);

$stmt = $pdo->query('SELECT id, email_normalized, role, status FROM users ORDER BY created_at');
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $count = $pdo->prepare('SELECT COUNT(*) FROM diary_entries WHERE owner_id = :o');
    $count->execute(['o' => $row['id']]);
    fwrite(STDOUT, sprintf(
        "%s  %s  role=%s  entries=%d\n",
        $row['id'],
        $row['email_normalized'],
        $row['role'],
        (int) $count->fetchColumn(),
    ));
}
