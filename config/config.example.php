<?php

declare(strict_types=1);

/**
 * Configuration template for the mental health diary.
 *
 * Copy this file to config/config.php and fill in the real values.
 *
 * config/config.php:
 *   - MUST stay outside the document root (the document root is public/).
 *   - MUST NOT be committed; it is listed in .gitignore.
 *   - SHOULD have restrictive permissions (chmod 0600, owned by the web user).
 *
 * It holds the master encryption key, so losing it makes every stored diary
 * entry unreadable, and leaking it makes every stored diary entry readable.
 */

return [
    'app' => [
        // 'production' or 'development'. Development shows detailed errors; never use it on the live site.
        'env' => 'production',

        // Canonical HTTPS base URL. Used for redirects and cookie scope.
        'base_url' => 'https://royhillis.co.uk',

        // Redirect plaintext HTTP to HTTPS in the application as well as in .htaccess.
        'force_https' => true,

        // Whether an "X-Forwarded-Proto: https" header may be believed when deciding that a
        // request arrived over TLS. A client can forge that header, so leaving this true
        // without a proxy that always overwrites it disables the HTTPS guard for anyone who
        // asks. Set it to true only if IONOS terminates TLS in front of PHP and PHP therefore
        // sees a plaintext connection on every request.
        'trust_forwarded_proto' => false,

        // This is a single-primary-user application: only one owner account is ever meant
        // to exist. Registration exists so that first owner account can be created, not as
        // an ongoing public signup form. Leave this true for a fresh/dev deployment that has
        // no owner account yet; set it to false once the owner account has been created so
        // /register stops being reachable by strangers. Viewer access is unaffected either
        // way - it is granted through invitations and /accept-invitation, not this switch.
        'registration_enabled' => true,
    ],

    'database' => [
        // MariaDB connection details from the IONOS hosting control panel.
        'host' => 'localhost',
        'port' => 3306,
        'name' => 'diary',
        'user' => 'diary_user',
        'password' => 'set-me',

        // utf8mb4 so emoji in free-text answers survive a round trip.
        'charset' => 'utf8mb4',
    ],

    'encryption' => [
        // Master key (KEK) that wraps the per-record data keys. 32 raw bytes, base64 encoded.
        // Generate with: php -r "echo base64_encode(random_bytes(32)), PHP_EOL;"
        // Rotating this value requires re-wrapping every row in encryption_keys.
        'master_key_base64' => '',
    ],

    'ai' => [
        // Master switch. false disables all AI calls: entries, milestones, the calendar and
        // deterministic trend metrics keep working, and AI surfaces show the unavailable notice.
        'enabled' => true,

        // Free-form provider label recorded with each stored recommendation for the processing trail.
        'provider' => 'example-provider',

        // HTTPS endpoint of the chat/completions style API. Must be https://.
        'endpoint' => 'https://api.example.com/v1/chat/completions',

        // Provider API key. Treat as a secret; it is never logged or rendered.
        'api_key' => '',

        // Model identifier recorded alongside each recommendation.
        'model' => 'example-model',

        // Hard request timeout in seconds. Keeps a slow provider from blocking a page load.
        'timeout_seconds' => 20,

        // Number of retries after the first failed attempt.
        'retries' => 1,
    ],

    'cron' => [
        // Shared secret required by /cron/purge, /cron/sessions and /cron/keys, compared in
        // constant time. Generate with: php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
        'token' => '',
    ],
];
