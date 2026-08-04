<?php

declare(strict_types=1);

/**
 * Post-deploy transport smoke check (Requirement 4.3).
 *
 *   php tools/smoke_check_transport.php HOST [--port=443] [--path=/]
 *
 *   HOST   the bare hostname to check, e.g. royhillis.co.uk (no scheme, no path)
 *   --port HTTPS port to connect to (default: 443)
 *   --path path to request over HTTPS when reading the security headers
 *          (default: /)
 *
 * This is a manual gate run by hand against a live, deployed host after a
 * release - not part of the automated test suite, and it makes real network
 * calls, which the PHPUnit suite for this project never does. Checks:
 *
 *   1. An HTTPS connection to HOST:PORT negotiates at least TLS 1.2.
 *   2. The HTTPS response to PATH carries Strict-Transport-Security,
 *      Content-Security-Policy, X-Content-Type-Options, Referrer-Policy and
 *      X-Frame-Options with acceptable values.
 *   3. A plaintext HTTP request to HOST:80/PATH redirects to an https:// URL
 *      on the same host.
 *
 * The judgement calls (is this header value acceptable, is this redirect the
 * right shape) live in Diary\Http\TransportChecks so they can be unit tested
 * with synthetic data; this script only fetches and prints.
 *
 * Exit codes: 0 every check passed, 1 at least one check failed or the host
 * could not be reached at all.
 */

use Diary\Http\TransportCheckResult;
use Diary\Http\TransportChecks;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

require __DIR__ . '/../vendor/autoload.php';

/**
 * @param list<string> $argv
 *
 * @return array{host: string, port: int, path: string}
 */
$parseArguments = static function (array $argv): array {
    $host = null;
    $port = 443;
    $path = '/';

    foreach (array_slice($argv, 1) as $argument) {
        if (preg_match('/^--(port|path)=(.*)$/', $argument, $matches) === 1) {
            if ($matches[1] === 'port') {
                $port = (int) $matches[2];
            } else {
                $path = $matches[2] === '' ? '/' : $matches[2];
            }
            continue;
        }

        if (str_starts_with($argument, '--')) {
            fwrite(STDERR, sprintf('Unrecognised argument "%s".%s', $argument, PHP_EOL));
            exit(1);
        }

        if ($host !== null) {
            fwrite(STDERR, 'Only one host may be given.' . PHP_EOL);
            exit(1);
        }

        $host = $argument;
    }

    if ($host === null || $host === '') {
        fwrite(STDERR, 'Usage: php tools/smoke_check_transport.php HOST [--port=443] [--path=/]' . PHP_EOL);
        exit(1);
    }

    if (str_contains($host, '://') || str_contains($host, '/')) {
        fwrite(STDERR, 'HOST must be a bare hostname, e.g. royhillis.co.uk (no scheme, no path).' . PHP_EOL);
        exit(1);
    }

    return ['host' => $host, 'port' => $port, 'path' => $path];
};

['host' => $host, 'port' => $port, 'path' => $path] = $parseArguments($argv);

/**
 * One HTTPS request, with the TLS floor pinned to 1.2, returning the response
 * headers and whether the connection itself was established.
 *
 * @return array{connected: bool, error: string, status: int, headers: array<string, string>}
 */
$fetchHttps = static function (string $url): array {
    $handle = curl_init();

    if ($handle === false) {
        return ['connected' => false, 'error' => 'Could not initialise curl.', 'status' => 0, 'headers' => []];
    }

    $rawHeaders = [];

    curl_setopt_array($handle, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_NOBODY => false,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_CONNECTTIMEOUT => 10,
        // The floor, not an exact pin: curl 7.54+ treats CURL_SSLVERSION_TLSv1_2
        // as "TLS 1.2 or later", so a successful connection is itself the proof
        // that TLS 1.0/1.1 were not used as a fallback.
        CURLOPT_SSLVERSION => CURL_SSLVERSION_TLSv1_2,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HEADERFUNCTION => static function ($curlHandle, string $line) use (&$rawHeaders): int {
            $colon = strpos($line, ':');
            if ($colon !== false) {
                $name = trim(substr($line, 0, $colon));
                $value = trim(substr($line, $colon + 1));
                if ($name !== '') {
                    $rawHeaders[$name] = $value;
                }
            }

            return strlen($line);
        },
    ]);

    $body = curl_exec($handle);
    $errorNumber = curl_errno($handle);
    $errorMessage = curl_error($handle);
    $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
    curl_close($handle);

    if ($body === false || $errorNumber !== 0) {
        return ['connected' => false, 'error' => $errorMessage !== '' ? $errorMessage : 'unknown transport error', 'status' => 0, 'headers' => []];
    }

    return ['connected' => true, 'error' => '', 'status' => $status, 'headers' => $rawHeaders];
};

$results = [];

$httpsUrl = sprintf('https://%s:%d%s', $host, $port, $path);
$httpsResponse = $fetchHttps($httpsUrl);

$results[] = TransportChecks::checkTlsVersionNegotiated($httpsResponse['connected'], $httpsResponse['error']);

if ($httpsResponse['connected']) {
    array_push($results, ...TransportChecks::checkSecurityHeaders($httpsResponse['headers']));
} else {
    // No connection means no headers to check either; report each as failed
    // rather than silently skipping it, so a broken host cannot look like a
    // partial pass.
    array_push($results, ...TransportChecks::checkSecurityHeaders([]));
}

$httpUrl = sprintf('http://%s%s', $host, $path);
$httpHandle = curl_init();
$httpStatus = 0;
$httpLocation = null;

if ($httpHandle !== false) {
    curl_setopt_array($httpHandle, [
        CURLOPT_URL => $httpUrl,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_NOBODY => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_HEADERFUNCTION => static function ($curlHandle, string $line) use (&$httpLocation): int {
            if (preg_match('/^location:\s*(.+?)\s*$/i', $line, $matches) === 1) {
                $httpLocation = $matches[1];
            }

            return strlen($line);
        },
    ]);

    curl_exec($httpHandle);
    $httpStatus = (int) curl_getinfo($httpHandle, CURLINFO_HTTP_CODE);
    curl_close($httpHandle);
}

$results[] = TransportChecks::checkHttpRedirectsToHttps($httpStatus, $httpLocation, $host);

$failed = 0;

foreach ($results as $result) {
    /** @var TransportCheckResult $result */
    $line = sprintf('[%s] %s', $result->passed() ? 'PASS' : 'FAIL', $result->name());

    if ($result->detail() !== '') {
        $line .= ' - ' . $result->detail();
    }

    fwrite(STDOUT, $line . PHP_EOL);

    if (!$result->passed()) {
        $failed++;
    }
}

fwrite(STDOUT, PHP_EOL);

if ($failed > 0) {
    fwrite(STDOUT, sprintf('%d of %d checks failed.%s', $failed, count($results), PHP_EOL));
    exit(1);
}

fwrite(STDOUT, sprintf('All %d checks passed.%s', count($results), PHP_EOL));
exit(0);
