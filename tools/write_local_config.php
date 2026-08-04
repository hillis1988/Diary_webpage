<?php

declare(strict_types=1);

$prod = require __DIR__ . '/../config/config.production.php.bak';

$local = $prod;
$local['app'] = [
    'env' => 'development',
    'base_url' => 'http://127.0.0.1:8080',
    'force_https' => false,
    'trust_forwarded_proto' => false,
    'registration_enabled' => true,
];
$local['database'] = [
    'host' => '127.0.0.1',
    'port' => 3307,
    'name' => 'diary',
    'user' => 'diary',
    'password' => 'diarylocal',
    'charset' => 'utf8mb4',
];
$local['encryption']['master_key_base64'] = 'Y+YqVasLnZGExObZcIkqkvB4ahwnN1Sxx95lTqJGEzs=';
$local['cron']['token'] = '8204c2a1e62c907aef10c661b03c1cb7aaa149d102ff6955a56cf070b4b85cc4';

$php = "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export($local, true) . ";\n";
file_put_contents(__DIR__ . '/../config/config.php', $php);
echo "Wrote local config/config.php\n";
