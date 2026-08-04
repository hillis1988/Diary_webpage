<?php

declare(strict_types=1);

namespace Diary\Tests\Unit\Auth;

use DateTimeImmutable;
use Diary\Auth\AuditAction;
use Diary\Auth\AuditLogRepository;
use Diary\Auth\AuditOutcome;
use Diary\Auth\AuthService;
use Diary\Auth\DefaultPasswordPolicy;
use Diary\Auth\IpHasher;
use Diary\Auth\PasswordHasher;
use Diary\Auth\Session;
use Diary\Auth\SessionRepository;
use Diary\Auth\UserRepository;
use Diary\Support\FixedClock;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Requirements 2.1 to 2.3: what a sign-in does, what it says when it refuses, and
 * what the fifth consecutive refusal does to the account.
 */
final class AuthServiceAuthenticateTest extends TestCase
{
    private const EMAIL = 'Roy@Example.com';
    private const PASSWORD = 'correct1horse2battery';
    private const IP = '203.0.113.5';

    private PDO $pdo;
    private FixedClock $clock;
    private UserRepository $users;
    private SessionRepository $sessions;
    private AuthService $service;

    protected function setUp(): void
    {
        $this->pdo = SqliteAuthTables::connection();
        $this->clock = FixedClock::at('2025-03-01 09:00:00');
        $this->users = new UserRepository($this->pdo);
        $this->sessions = new SessionRepository($this->pdo);

        $this->service = new AuthService(
            $this->users,
            new DefaultPasswordPolicy(),
            $this->clock,
            // The test-only hasher keeps these tests quick; which algorithm
            // production picks is covered by PasswordHasherTest.
            PasswordHasher::forTests(),
            $this->sessions,
            new AuditLogRepository($this->pdo),
            new IpHasher('unit-test-ip-key'),
        );

        self::assertTrue($this->service->register(self::EMAIL, self::PASSWORD)->isOk());
    }

    public function testCorrectCredentialsStartOneSessionFrozenToTheAccount(): void
    {
        $result = $this->service->authenticate('  ROY@example.com  ', self::PASSWORD, $this->now(), self::IP);

        self::assertTrue($result->isOk(), 'the registered password should be accepted');

        $session = $result->value();
        self::assertInstanceOf(Session::class, $session);

        $account = $this->users->findByEmail(self::EMAIL);
        self::assertNotNull($account);

        $rows = SqliteAuthTables::sessions($this->pdo);
        self::assertCount(1, $rows, 'exactly one session row');
        self::assertSame($account->id->toString(), $rows[0]['user_id']);
        self::assertSame($account->role->value, $rows[0]['context_role'], 'role frozen from the account');
        self::assertSame($account->dataOwnerId->toString(), $rows[0]['data_owner_id']);
        self::assertSame('2025-03-01 09:00:00', $rows[0]['created_at']);
        self::assertSame('2025-03-01 09:00:00', $rows[0]['last_activity_at']);
        self::assertNull($rows[0]['terminated_at']);

        // Only the hash of the cookie token is stored, never the token itself.
        $token = $session->issuedToken();
        self::assertNotNull($token);
        self::assertSame(hash('sha256', $token->value()), $rows[0]['id']);
        self::assertSame($session->id->toString(), $rows[0]['id']);
        self::assertNotSame($token->value(), $rows[0]['id']);

        $audit = SqliteAuthTables::auditLog($this->pdo);
        self::assertCount(1, $audit);
        self::assertSame(AuditAction::SignIn->value, $audit[0]['action']);
        self::assertSame(AuditOutcome::Success->value, $audit[0]['outcome']);
        self::assertSame('owner', $audit[0]['context_role']);
        self::assertSame($account->id->toString(), $audit[0]['actor_user_id']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', (string) $audit[0]['ip_hash']);
    }

    public function testAWrongPasswordAndAnUnknownEmailAreRefusedIdentically(): void
    {
        $wrongPassword = $this->service->authenticate(self::EMAIL, 'wrong1password2here', $this->now(), self::IP);
        $unknownEmail = $this->service->authenticate('nobody@example.com', self::PASSWORD, $this->now(), self::IP);

        self::assertTrue($wrongPassword->isFailure());
        self::assertTrue($unknownEmail->isFailure());
        self::assertSame(AuthService::INCORRECT_CREDENTIALS_MESSAGE, $wrongPassword->message());
        self::assertSame($wrongPassword->message(), $unknownEmail->message(), 'one message for both refusals');
        self::assertSame($wrongPassword->errorCode(), $unknownEmail->errorCode());
        self::assertSame([], $wrongPassword->fieldMessages(), 'no field is named, so neither is confirmed');

        self::assertSame([], SqliteAuthTables::sessions($this->pdo), 'a refusal starts no session');

        // Only the attempt against a real account can be counted; the unknown email
        // has nothing to count against.
        self::assertSame(1, $this->failedLoginCount());

        $audit = SqliteAuthTables::auditLog($this->pdo);
        self::assertCount(2, $audit);

        foreach ($audit as $row) {
            self::assertSame(AuditAction::SignInFailed->value, $row['action']);
            self::assertSame(AuditOutcome::Failure->value, $row['outcome']);
            self::assertSame('anonymous', $row['context_role'], 'a failure has no session, so no role');
        }

        // Rows are ULID-ordered, so the wrong-password attempt comes first: it names
        // the account it was aimed at, while the unknown email has no actor at all.
        $account = $this->users->findByEmail(self::EMAIL);
        self::assertNotNull($account);
        self::assertSame($account->id->toString(), $audit[0]['actor_user_id']);
        self::assertNull($audit[1]['actor_user_id']);
    }

    public function testTheFifthConsecutiveFailureLocksTheAccountForFifteenMinutes(): void
    {
        for ($attempt = 1; $attempt <= 4; $attempt++) {
            $result = $this->service->authenticate(self::EMAIL, 'wrong1password2here', $this->now(), self::IP);

            self::assertSame(AuthService::INCORRECT_CREDENTIALS_ERROR_CODE, $result->errorCode());
            self::assertSame($attempt, $this->failedLoginCount());
            self::assertNull($this->lockedUntil(), 'four failures do not lock the account');
        }

        $fifth = $this->service->authenticate(self::EMAIL, 'wrong1password2here', $this->now(), self::IP);

        self::assertSame(
            AuthService::INCORRECT_CREDENTIALS_ERROR_CODE,
            $fifth->errorCode(),
            'the failure that sets the lock still reads as an ordinary wrong password'
        );
        self::assertSame(5, $this->failedLoginCount());
        self::assertSame('2025-03-01 09:15:00', $this->lockedUntil(), 'locked for fifteen minutes');

        $actions = array_column(SqliteAuthTables::auditLog($this->pdo), 'action');
        self::assertSame(5, count(array_filter($actions, static fn (string $a): bool => $a === 'sign_in_failed')));
        self::assertContains(AuditAction::AccountLocked->value, $actions, 'the lockout itself is recorded');
    }

    public function testALockedAccountIsRefusedEvenWithTheCorrectPassword(): void
    {
        $this->lockTheAccount();
        $auditBefore = count(SqliteAuthTables::auditLog($this->pdo));

        $result = $this->service->authenticate(self::EMAIL, self::PASSWORD, $this->now(), self::IP);

        self::assertTrue($result->isFailure());
        self::assertSame(AuthService::ACCOUNT_LOCKED_ERROR_CODE, $result->errorCode());
        self::assertSame(AuthService::ACCOUNT_LOCKED_MESSAGE, $result->message());
        self::assertSame([], SqliteAuthTables::sessions($this->pdo), 'a locked account gets no session');

        // Refused before the password was looked at, so the counter is untouched.
        self::assertSame(5, $this->failedLoginCount());
        self::assertSame('2025-03-01 09:15:00', $this->lockedUntil(), 'the window is not extended by the attempt');

        $audit = SqliteAuthTables::auditLog($this->pdo);
        self::assertCount($auditBefore + 1, $audit);
        self::assertSame(AuditOutcome::Denied->value, $audit[$auditBefore]['outcome']);
    }

    public function testOnceTheLockExpiresTheCorrectPasswordWorksAndCountingStartsAgain(): void
    {
        $this->lockTheAccount();

        $this->clock->advanceMinutes(15);

        $result = $this->service->authenticate(self::EMAIL, self::PASSWORD, $this->now(), self::IP);

        self::assertTrue($result->isOk(), 'the lock is spent after fifteen minutes');
        self::assertSame(0, $this->failedLoginCount(), 'a success resets the counter');
        self::assertNull($this->lockedUntil(), 'and clears the lock');
        self::assertCount(1, SqliteAuthTables::sessions($this->pdo));

        $wrong = $this->service->authenticate(self::EMAIL, 'wrong1password2here', $this->now(), self::IP);

        self::assertSame(AuthService::INCORRECT_CREDENTIALS_ERROR_CODE, $wrong->errorCode());
        self::assertSame(1, $this->failedLoginCount(), 'counting restarts from one');
        self::assertNull($this->lockedUntil());
    }

    public function testTheAuditTrailHoldsIdentifiersAndOutcomesOnly(): void
    {
        $this->service->authenticate(self::EMAIL, self::PASSWORD, $this->now(), self::IP);
        $this->service->authenticate(self::EMAIL, 'wrong1password2here', $this->now(), self::IP);
        $this->service->authenticate('nobody@example.com', self::PASSWORD, $this->now(), self::IP);

        $audit = SqliteAuthTables::auditLog($this->pdo);
        self::assertNotEmpty($audit);

        $forbidden = [self::PASSWORD, self::EMAIL, strtolower(self::EMAIL), 'nobody@example.com', self::IP];

        foreach ($audit as $row) {
            foreach ($row as $column => $value) {
                if (!is_string($value)) {
                    continue;
                }

                foreach ($forbidden as $secret) {
                    self::assertStringNotContainsStringIgnoringCase(
                        $secret,
                        $value,
                        sprintf('audit_log.%s must not carry credentials or addresses', $column)
                    );
                }
            }
        }
    }

    private function lockTheAccount(): void
    {
        for ($attempt = 1; $attempt <= AuthService::MAX_FAILED_ATTEMPTS; $attempt++) {
            $this->service->authenticate(self::EMAIL, 'wrong1password2here', $this->now(), self::IP);
        }

        self::assertNotNull($this->lockedUntil(), 'five failures should have locked the account');
    }

    private function now(): DateTimeImmutable
    {
        return $this->clock->now();
    }

    private function failedLoginCount(): int
    {
        $account = $this->users->findByEmail(self::EMAIL);
        self::assertNotNull($account);

        return $account->failedLoginCount;
    }

    private function lockedUntil(): ?string
    {
        $account = $this->users->findByEmail(self::EMAIL);
        self::assertNotNull($account);

        return $account->lockedUntil?->format('Y-m-d H:i:s');
    }
}
