# AGENTS.md

## Cursor Cloud specific instructions

### Where the code lives
The application code lives on the `1.Requirements_gathering` branch. The `main`
branch contains only `README.md`. Feature branches for this work are based on
`1.Requirements_gathering`, so make sure you are on a branch that actually
contains the app (`composer.json`, `src/`, `public/`, `migrations/`).

### What this is
A single PHP 8.2+ server-rendered web app: a personal mental-health diary with
CBT-style AI feedback, milestones, calendar and progress summaries. No Node/JS
build step (assets under `public/assets/` are static). Bespoke micro-framework
(custom router + middleware) with a single front controller at `public/index.php`
(document root is `public/`). Data is encrypted at rest, so DB columns like the
user email are stored as `email_normalized`/`email_display`, not plaintext.

Standard commands live in `composer.json` (`scripts`) and `DEPLOYMENT.md`; the
notes below only cover the non-obvious cloud gotchas.

### Services

Diary web app (PHP)
- Requires PHP 8.2+ (8.3 is installed) with `pdo_mysql`, `mbstring`, `openssl`,
  `curl`, `json`, plus `pdo_sqlite` (used by some unit tests).
- Requires a running MariaDB/MySQL server.

### MariaDB (must be started manually each session)
The DB server is NOT auto-started. Start it before running the app or the
integration tests:

```bash
sudo mariadbd --user=mysql &   # listens on 127.0.0.1:3306
```

Credentials configured in this environment: `root`/`root` over TCP
(`127.0.0.1:3306`). Databases: `diary_test` (used by the integration suite, see
`phpunit.xml`) and `diary` (used by the dev app, owned by `diary_user`/`diary_pass`).
If the databases or the `root`@`127.0.0.1` TCP user are missing after a fresh
boot, recreate them, then re-run migrations.

### Config (git-ignored, may need recreating)
`config/config.php` is git-ignored (holds the master encryption key + DB creds).
Copy `config/config.example.php` to `config/config.php` and, for local dev, set
`app.env='development'`, `app.base_url='http://127.0.0.1:8000'`,
`app.force_https=false`, `ai.enabled=false` (no external AI provider is wired up
locally; diary/milestones/calendar keep working and AI surfaces show an
"unavailable" notice), point `database` at the `diary` DB above, and generate a
master key with `php -r "echo base64_encode(random_bytes(32));"`. Losing this key
makes previously stored entries unreadable, so if you recreate the config you may
need to reset the `diary` DB (re-run migrations) to start clean.

### Run the app (development)
```bash
php tools/migrate.php               # apply migrations to the diary DB
php -S 127.0.0.1:8000 -t public     # docroot MUST be public/
```
Registration is a one-time owner-account bootstrap: visit `/register`, create the
owner (password >= 12 chars with a letter and a digit), and you are auto-logged
in and dropped on the diary home.

### Tests
The full suite (Unit, Property, Integration) needs MariaDB running for the
Integration suite; Unit/Property run without it.

Gotcha: `composer test` fails with `phpunit: Permission denied` because `vendor/`
is committed without the exec bit on `vendor/bin/phpunit`. Invoke PHPUnit through
PHP instead:

```bash
php vendor/bin/phpunit                       # all suites
php vendor/bin/phpunit --testsuite Unit      # or Property / Integration
```

The line `Session resolution failed: SQLSTATE[HY000] ... no such table: sessions`
printed mid-run is expected output from an intentional SQLite negative test, not a
failure.

### Lint
No linter/static analyzer is configured (no PHPStan/Psalm/PHP-CS-Fixer). The only
checks available are `php -l <file>` for syntax and PHPUnit's strict mode.
