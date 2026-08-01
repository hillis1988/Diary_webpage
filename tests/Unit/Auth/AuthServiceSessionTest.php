<?php

declare(strict_types=1);

namespace Diary\Tests\Unit\Auth;

use DateTimeImmutable;
use Diary\Auth\AuditLogRepository;
use Diary\Auth\AuthService;
use Diary\Auth\ContextRole;
use Diary\Auth\DefaultPasswordPolicy;
use Diary\Auth\IpHasher;
use Diary\Auth\PasswordHasher;
use Diary\Auth\SecurityContext;
use Diary\Auth\Session;
use Diary\Auth\SessionRepository;
use Diary\Auth\SessionToken;
use Diary\Auth\UserAccount;
use Diary\Auth\UserRepository;
use Diary\Support\FixedClock;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Requirements 2.4 and 2.5: a session resolves only while it is neither signed out
 * nor idle for thirty minutes, and resolving one keeps it alive.
 */
final class AuthServiceSessionTest extends TestCase
{
    private const EMAIL = 'roy@example.com';
    private const PASSWORD = 'correct1horse2battery';

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
            new PasswordHasher(),
            $this->sessions,
            new AuditLogRepository($this->pdo),
            new IpHasher('unit-test-ip-key'),
        );

        self::assertTrue($this->service->register(self::EMAIL, self::PASSWORD)->isOk());
    }

    public function testAFreshSessionResolvesToTheContextFrozenAtSignIn(): void
    {
        $token = $this->signIn();
        $account = $this->account();

        $context = $this->service->resolveSession($token->value(), $this->now());

        self::assertInstanceOf(SecurityContext::class, $context);
        self::assertTrue($context->isAuthenticated());
        self::assertSame(ContextRole::Owner, $context->contextRole);
        self::assertNotNull($context->userId);
        self::assertNotNull($context->dataOwnerId);
        self::assertSame($account->id->toString(), $context->userId->toString());
        self::assertSame($account->dataOwnerId->toString(), $context->dataOwnerId->toString());
    }

    public function testEachResolutionSlidesTheIdleWindowForward(): void
    {
        $token = $this->signIn();

        $this->clock->advanceMinutes(29);
        self::assertNotNull($this->service->resolveSession($token->value(), $this->now()));
        self::assertSame('2025-03-01 09:29:00', $this->lastActivityAt(), 'activity is recorded, not just read');

        // Measured from the last request rather than from sign-in, so a session in
        // continuous use never times out.
        $this->clock->advanceMinutes(29);
        self::assertNotNull($this->service->resolveSession($token->value(), $this->now()));
        self::assertSame('2025-03-01 09:58:00', $this->lastActivityAt());
        self::assertNull($this->terminatedAt());
    }

    public function testJustUnderThirtyIdleMinutesStillResolvesAndThirtyDoesNot(): void
    {
        $token = $this->signIn();

        $this->clock->advanceSeconds(30 * 60 - 1);
        self::assertNotNull(
            $this->service->resolveSession($token->value(), $this->now()),
            'one second short of the timeout is still a live session'
        );

        // The slide above reset the window, so thirty minutes from here is the boundary.
        $this->clock->advanceMinutes(AuthService::IDLE_TIMEOUT_MINUTES);
        self::assertNull(
            $this->service->resolveSession($token->value(), $this->now()),
            'exactly thirty idle minutes is over the line'
        );
    }

    public function testAnIdleSessionIsTerminatedSoItCannotComeBack(): void
    {
        $token = $this->signIn();

        $this->clock->advanceMinutes(AuthService::IDLE_TIMEOUT_MINUTES);
        self::assertNull($this->service->resolveSession($token->value(), $this->now()));

        // Requirement 2.5 ends the session rather than ignoring it, so the row says so.
        self::assertSame('2025-03-01 09:30:00', $this->terminatedAt());
        self::assertSame('2025-03-01 09:00:00', $this->lastActivityAt(), 'a refused request is not activity');

        $session = $this->sessions->findById($token->id());
        self::assertInstanceOf(Session::class, $session);
        self::assertFalse(AuthService::isSessionValid($session, $this->now()));

        // And a later request with the same cookie, well inside a fresh window, gets nothing.
        $this->clock->advanceMinutes(1);
        self::assertNull($this->service->resolveSession($token->value(), $this->now()));
    }

    public function testSignOutMakesTheSameTokenUselessAndIsIdempotent(): void
    {
        $token = $this->signIn();

        $this->service->signOut($token->id(), $this->now());

        self::assertSame('2025-03-01 09:00:00', $this->terminatedAt());
        self::assertNull(
            $this->service->resolveSession($token->value(), $this->now()),
            'a signed-out session never resolves again'
        );

        $this->clock->advanceMinutes(5);
        $this->service->signOut($token->id(), $this->now());

        self::assertSame(
            '2025-03-01 09:00:00',
            $this->terminatedAt(),
            'signing out twice does not move when access actually ended'
        );
    }

    public function testNoCookieAnUnknownTokenAndAMalformedOneAreAllAnonymous(): void
    {
        $this->signIn();

        foreach (['', 'not-a-token', str_repeat('z', 64), SessionToken::generate()->value()] as $candidate) {
            self::assertNull(
                $this->service->resolveSession($candidate, $this->now()),
                sprintf('"%s" should not resolve to a session', $candidate)
            );
        }

        $anonymous = SecurityContext::anonymous();
        self::assertTrue($anonymous->isAnonymous());
        self::assertFalse($anonymous->isOwner());
        self::assertNull($anonymous->userId);
        self::assertNull($anonymous->dataOwnerId, 'an anonymous context carries no owner scope');
    }

    public function testRevokingAnAccountEndsEveryLiveSessionAtOnce(): void
    {
        $first = $this->signIn();
        $this->clock->advanceMinutes(1);
        $second = $this->signIn();

        $ended = $this->sessions->terminateAllForUser($this->account()->id, $this->now());

        self::assertSame(2, $ended);
        self::assertNull($this->service->resolveSession($first->value(), $this->now()));
        self::assertNull($this->service->resolveSession($second->value(), $this->now()));
        self::assertSame(0, $this->sessions->terminateAllForUser($this->account()->id, $this->now()));
    }

    private function signIn(): SessionToken
    {
        $result = $this->service->authenticate(self::EMAIL, self::PASSWORD, $this->now());
        self::assertTrue($result->isOk());

        $session = $result->value();
        self::assertInstanceOf(Session::class, $session);

        $token = $session->issuedToken();
        self::assertNotNull($token);

        return $token;
    }

    private function account(): UserAccount
    {
        $account = $this->users->findByEmail(self::EMAIL);
        self::assertNotNull($account);

        return $account;
    }

    private function now(): DateTimeImmutable
    {
        return $this->clock->now();
    }

    private function lastActivityAt(): ?string
    {
        return $this->sessions->findById($this->onlySessionId())?->lastActivityAt->format('Y-m-d H:i:s');
    }

    private function terminatedAt(): ?string
    {
        return $this->sessions->findById($this->onlySessionId())?->terminatedAt?->format('Y-m-d H:i:s');
    }

    private function onlySessionId(): string
    {
        $rows = SqliteAuthTables::sessions($this->pdo);
        self::assertCount(1, $rows, 'these assertions read the single session under test');

        return (string) $rows[0]['id'];
    }
}
