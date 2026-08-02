<?php

declare(strict_types=1);

namespace Diary\Tests\Unit\Http;

use Diary\Auth\SessionRepository;
use Diary\Http\CronAuth;
use Diary\Http\CronController;
use Diary\Http\Request;
use Diary\Storage\ConnectionFactory;
use Diary\Storage\Crypto;
use Diary\Storage\KeyRing;
use Diary\Storage\KeyRotationService;
use Diary\Storage\PayloadCodec;
use Diary\Storage\PurgeJobRepository;
use Diary\Storage\PurgeService;
use Diary\Support\FixedClock;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * The three cron endpoints end to end (Requirements 4.2, 4.5): a missing or
 * wrong token is refused with no work done, a correct token runs the bounded
 * slice, and running each endpoint twice in a row is safe.
 *
 * Uses the same in-memory SQLite schema shape as PurgeServiceTest and
 * KeyRotationServiceTest, since a cron call exercises all three services
 * together.
 */
final class CronControllerTest extends TestCase
{
    private const TOKEN = 'cron-secret-token';

    private PDO $pdo;
    private FixedClock $clock;
    private CronController $controller;
    private KeyRing $keyRing;

    protected function setUp(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_sqlite is not loaded.');
        }

        $this->pdo = ConnectionFactory::fromDsn('sqlite::memory:');
        $this->createSchema();

        $this->clock = FixedClock::at('2025-03-01 09:30:00');

        $masterKey = str_repeat("\x2a", KeyRing::KEY_LENGTH);
        $this->keyRing = new KeyRing($this->pdo, $masterKey, $this->clock);
        $codec = new PayloadCodec(new Crypto($this->keyRing));

        $this->controller = new CronController(
            new CronAuth(self::TOKEN),
            new PurgeService($this->pdo, new PurgeJobRepository($this->pdo), $this->clock),
            new SessionRepository($this->pdo),
            new KeyRotationService($this->pdo, $this->keyRing, $codec),
            $this->clock,
        );
    }

    private function createSchema(): void
    {
        $this->pdo->exec(
            'CREATE TABLE users (
                id                     TEXT    NOT NULL PRIMARY KEY,
                email_normalized       TEXT    NOT NULL,
                email_display          TEXT    NOT NULL,
                password_hash          TEXT        NULL,
                role                   TEXT    NOT NULL,
                data_owner_id          TEXT    NOT NULL,
                status                 TEXT    NOT NULL DEFAULT \'invited\',
                failed_login_count     INTEGER NOT NULL DEFAULT 0,
                locked_until           TEXT        NULL,
                deletion_requested_at  TEXT        NULL,
                created_at             TEXT    NOT NULL,
                updated_at             TEXT    NOT NULL
            )'
        );

        $this->pdo->exec(
            'CREATE TABLE sessions (
                id               TEXT NOT NULL PRIMARY KEY,
                user_id          TEXT NOT NULL,
                context_role     TEXT NOT NULL,
                data_owner_id    TEXT NOT NULL,
                created_at       TEXT NOT NULL,
                last_activity_at TEXT NOT NULL,
                terminated_at    TEXT     NULL
            )'
        );

        $this->pdo->exec(
            'CREATE TABLE diary_entries (
                id                 TEXT NOT NULL PRIMARY KEY,
                owner_id           TEXT NOT NULL,
                entry_date         TEXT NOT NULL,
                key_id             TEXT NOT NULL,
                nonce              BLOB NOT NULL,
                payload_ciphertext BLOB NOT NULL,
                created_at         TEXT NOT NULL,
                updated_at         TEXT NOT NULL
            )'
        );

        $this->pdo->exec(
            'CREATE TABLE cbt_recommendations (
                id                 TEXT NOT NULL PRIMARY KEY,
                entry_id           TEXT NOT NULL,
                status             TEXT NOT NULL,
                key_id             TEXT     NULL,
                nonce              BLOB     NULL,
                payload_ciphertext BLOB     NULL,
                provider           TEXT     NULL,
                model              TEXT     NULL,
                attempt_count      INTEGER  NOT NULL DEFAULT 0,
                generated_at       TEXT     NULL
            )'
        );

        $this->pdo->exec(
            'CREATE TABLE milestones (
                id                 TEXT NOT NULL PRIMARY KEY,
                owner_id           TEXT NOT NULL,
                milestone_date     TEXT NOT NULL,
                key_id             TEXT NOT NULL,
                nonce              BLOB NOT NULL,
                payload_ciphertext BLOB NOT NULL,
                created_at         TEXT NOT NULL,
                updated_at         TEXT NOT NULL
            )'
        );

        $this->pdo->exec(
            'CREATE TABLE purge_jobs (
                id            TEXT    NOT NULL PRIMARY KEY,
                user_id       TEXT    NOT NULL,
                requested_at  TEXT    NOT NULL,
                completed_at  TEXT        NULL,
                attempt_count INTEGER NOT NULL DEFAULT 0,
                last_error    TEXT        NULL
            )'
        );

        $this->pdo->exec(
            'CREATE TABLE audit_log (
                id            TEXT NOT NULL PRIMARY KEY,
                actor_user_id TEXT     NULL,
                context_role  TEXT NOT NULL DEFAULT \'anonymous\',
                action        TEXT NOT NULL,
                target_type   TEXT     NULL,
                target_id     TEXT     NULL,
                outcome       TEXT NOT NULL,
                ip_hash       TEXT     NULL,
                occurred_at   TEXT NOT NULL
            )'
        );

        $this->pdo->exec(
            'CREATE TABLE encryption_keys (
                id          CHAR(26)   NOT NULL PRIMARY KEY,
                wrapped_dek BLOB       NOT NULL,
                wrap_nonce  BLOB       NOT NULL,
                created_at  DATETIME   NOT NULL,
                retired_at  DATETIME   NULL
            )'
        );
    }

    private function authorisedRequest(string $path): Request
    {
        return Request::of('GET', $path, query: ['token' => self::TOKEN]);
    }

    /** A valid-looking 26-character ULID, distinguished only by its suffix. */
    private function ulid(string $suffix): string
    {
        return '0' . str_repeat('1', 25 - strlen($suffix)) . $suffix;
    }

    // -- token rejection --------------------------------------------------

    public function testPurgeRejectsAMissingToken(): void
    {
        $response = $this->controller->purge(Request::of('GET', '/cron/purge'));

        self::assertSame(401, $response->status());
    }

    public function testSessionsRejectsAWrongToken(): void
    {
        $response = $this->controller->sessions(Request::of('GET', '/cron/sessions', query: ['token' => 'wrong']));

        self::assertSame(401, $response->status());
    }

    public function testKeysRejectsAMissingToken(): void
    {
        $response = $this->controller->keys(Request::of('GET', '/cron/keys'));

        self::assertSame(401, $response->status());
    }

    public function testARejectedCallDoesNoWork(): void
    {
        // A purge job outstanding for a user that no longer exists would be a
        // no-op anyway; use a session instead, which an unauthenticated call
        // must not touch.
        $ownerId = $this->ulid('1');
        $this->pdo->exec(
            "INSERT INTO users (id, email_normalized, email_display, role, data_owner_id, status, created_at, updated_at)
             VALUES ('{$ownerId}', 'u1@example.com', 'u1@example.com', 'owner', '{$ownerId}', 'active', '2025-01-01 00:00:00', '2025-01-01 00:00:00')"
        );
        $this->pdo->exec(
            "INSERT INTO sessions (id, user_id, context_role, data_owner_id, created_at, last_activity_at, terminated_at)
             VALUES ('s1', '{$ownerId}', 'owner', '{$ownerId}', '2025-01-01 00:00:00', '2025-01-01 00:00:00', NULL)"
        );

        $this->controller->sessions(Request::of('GET', '/cron/sessions', query: ['token' => 'wrong']));

        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM sessions')->fetchColumn());
    }

    // -- successful runs ----------------------------------------------------

    public function testPurgeRunsASuccessfulSlice(): void
    {
        $ownerId = $this->ulid('1');
        $this->pdo->exec(
            "INSERT INTO users (id, email_normalized, email_display, role, data_owner_id, status, created_at, updated_at)
             VALUES ('{$ownerId}', 'u1@example.com', 'u1@example.com', 'owner', '{$ownerId}', 'active', '2025-01-01 00:00:00', '2025-01-01 00:00:00')"
        );
        $this->pdo->exec(
            "INSERT INTO purge_jobs (id, user_id, requested_at, completed_at, attempt_count, last_error)
             VALUES ('j1', '{$ownerId}', '2025-01-01 00:00:00', NULL, 0, NULL)"
        );

        $response = $this->controller->purge($this->authorisedRequest('/cron/purge'));

        self::assertSame(200, $response->status());
        self::assertStringContainsString('succeeded=1', $response->body());
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM users')->fetchColumn());
    }

    public function testSessionsRunsASuccessfulCleanup(): void
    {
        $ownerId = $this->ulid('1');
        $this->pdo->exec(
            "INSERT INTO users (id, email_normalized, email_display, role, data_owner_id, status, created_at, updated_at)
             VALUES ('{$ownerId}', 'u1@example.com', 'u1@example.com', 'owner', '{$ownerId}', 'active', '2025-01-01 00:00:00', '2025-01-01 00:00:00')"
        );
        $this->pdo->exec(
            "INSERT INTO sessions (id, user_id, context_role, data_owner_id, created_at, last_activity_at, terminated_at)
             VALUES ('s1', '{$ownerId}', 'owner', '{$ownerId}', '2025-01-01 00:00:00', '2025-01-01 00:00:00', '2025-01-01 01:00:00')"
        );

        $response = $this->controller->sessions($this->authorisedRequest('/cron/sessions'));

        self::assertSame(200, $response->status());
        self::assertStringContainsString('deleted=1', $response->body());
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM sessions')->fetchColumn());
    }

    public function testKeysRunsASuccessfulReEncryptionAndRetiresTheUnreferencedKey(): void
    {
        $oldKey = $this->keyRing->createKey();

        $dek = $this->keyRing->keyFor($oldKey);
        $nonce = random_bytes(12);
        $tag = '';
        $json = json_encode(['mood_rating' => 5, 'sleep_quality' => null, 'events' => '', 'thoughts' => '', 'emotions' => '', 'schema_version' => 1]);
        $ciphertext = openssl_encrypt($json, Crypto::CIPHER, $dek, OPENSSL_RAW_DATA, $nonce, $tag, 'diary_entries' . "\0" . 'e1', 16);

        $statement = $this->pdo->prepare(
            'INSERT INTO diary_entries (id, owner_id, entry_date, key_id, nonce, payload_ciphertext, created_at, updated_at)
             VALUES (\'e1\', \'owner1\', \'2025-03-01\', :key_id, :nonce, :ct, :now, :now)'
        );
        $statement->bindValue(':key_id', $oldKey);
        $statement->bindValue(':nonce', $nonce, PDO::PARAM_LOB);
        $statement->bindValue(':ct', $ciphertext . $tag, PDO::PARAM_LOB);
        $statement->bindValue(':now', '2025-01-01 00:00:00');
        $statement->execute();

        $this->clock->advanceDays(1);
        $this->keyRing->createKey();
        $this->keyRing->forget();

        $response = $this->controller->keys($this->authorisedRequest('/cron/keys'));

        self::assertSame(200, $response->status());
        self::assertStringContainsString('re_encrypted=1', $response->body());
        self::assertStringContainsString('retired_keys=1', $response->body());
    }

    // -- idempotence ----------------------------------------------------

    public function testRunningEachEndpointTwiceInARowIsSafe(): void
    {
        $ownerId = $this->ulid('1');
        $this->pdo->exec(
            "INSERT INTO users (id, email_normalized, email_display, role, data_owner_id, status, created_at, updated_at)
             VALUES ('{$ownerId}', 'u1@example.com', 'u1@example.com', 'owner', '{$ownerId}', 'active', '2025-01-01 00:00:00', '2025-01-01 00:00:00')"
        );
        $this->pdo->exec(
            "INSERT INTO purge_jobs (id, user_id, requested_at, completed_at, attempt_count, last_error)
             VALUES ('j1', '{$ownerId}', '2025-01-01 00:00:00', NULL, 0, NULL)"
        );

        $first = $this->controller->purge($this->authorisedRequest('/cron/purge'));
        $second = $this->controller->purge($this->authorisedRequest('/cron/purge'));

        self::assertSame(200, $first->status());
        self::assertSame(200, $second->status());
        self::assertStringContainsString('attempted=0', $second->body());

        $firstSessions = $this->controller->sessions($this->authorisedRequest('/cron/sessions'));
        $secondSessions = $this->controller->sessions($this->authorisedRequest('/cron/sessions'));
        self::assertSame(200, $firstSessions->status());
        self::assertSame(200, $secondSessions->status());

        $firstKeys = $this->controller->keys($this->authorisedRequest('/cron/keys'));
        $secondKeys = $this->controller->keys($this->authorisedRequest('/cron/keys'));
        self::assertSame(200, $firstKeys->status());
        self::assertSame(200, $secondKeys->status());
    }
}
