<?php

declare(strict_types=1);

/**
 * Login as the local preview user and hit /summary with a date range.
 */

$base = 'http://127.0.0.1:8080';
$cookieFile = sys_get_temp_dir() . '/diary-local-cookies.txt';
@unlink($cookieFile);

function request(string $method, string $url, string $cookieFile, array $opts = []): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_COOKIEJAR => $cookieFile,
        CURLOPT_COOKIEFILE => $cookieFile,
        CURLOPT_FOLLOWLOCATION => false,
    ] + $opts);
    $raw = curl_exec($ch);
    if ($raw === false) {
        throw new RuntimeException(curl_error($ch));
    }
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $parts = explode("\r\n\r\n", $raw, 2);
    return [$status, $parts[0] ?? '', $parts[1] ?? ''];
}

[, , $loginHtml] = request('GET', $base . '/login', $cookieFile);
if (!preg_match('/name="(_csrf)"\s+value="([^"]*)"/', $loginHtml, $m)) {
    fwrite(STDERR, "No CSRF on login page\n");
    exit(1);
}

[, $headers] = request('POST', $base . '/login', $cookieFile, [
    CURLOPT_POSTFIELDS => http_build_query([
        $m[1] => $m[2],
        'email' => 'local@preview.test',
        'password' => 'LocalPreview1!',
    ]),
]);

if (!preg_match('/^Location:\s*(.+)$/mi', $headers, $loc)) {
    fwrite(STDERR, "Login did not redirect\n{$headers}\n");
    exit(1);
}
fwrite(STDOUT, 'login redirect -> ' . trim($loc[1]) . "\n");

$start = '2026-07-06';
$end = '2026-08-04';
$url = $base . '/summary?' . http_build_query(['start' => $start, 'end' => $end]);
[$status, , $body] = request('GET', $url, $cookieFile);

fwrite(STDOUT, "summary status={$status} bytes=" . strlen($body) . "\n");
foreach ([
    'More entries are needed',
    'temporarily unavailable',
    'Entries considered',
    'trend-panel',
    'Mood rating',
    'AI CBT',
] as $needle) {
    fwrite(STDOUT, (str_contains($body, $needle) ? 'YES' : 'no ') . "  {$needle}\n");
}

file_put_contents(__DIR__ . '/../.local-mariadb/last-summary.html', $body);
fwrite(STDOUT, "Wrote .local-mariadb/last-summary.html\n");
