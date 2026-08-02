<?php

declare(strict_types=1);

namespace Diary\Tests\Unit\Http;

use Diary\Access\AccessControlService;
use Diary\Auth\AuditLogRepository;
use Diary\Auth\AuthService;
use Diary\Auth\DefaultPasswordPolicy;
use Diary\Auth\PasswordHasher;
use Diary\Auth\PasswordPolicy;
use Diary\Auth\SessionCookie;
use Diary\Auth\SessionRepository;
use Diary\Auth\UserRepository;
use Diary\Http\CsrfGuard;
use Diary\Http\RegistrationController;
use Diary\Http\Request;
use Diary\Support\FixedClock;
use PDO;
use PHPUnit\Framework\TestCase;
use Diary\Tests\Unit\Auth\SqliteAuthTables;

/**
 * The registration page and controller (Requirements 1.1 to 1.5).
 *
 * Uses a real {@see AuthService} against an in-memory SQLite schema standing in
 * for migrations 001, 002 and 008, following the pattern in
 * tests/Unit/Auth/AuthServiceRegistrationTest.php and
 * tests/Unit/Http/MilestoneControllerTest.php: what matters here is what ends
 * up (or does not end up) in the `users` and `sessions` tables, not a mocked
 * collaborator.
 */
final class RegistrationControllerTest extends TestCase
{
    private const PASSWORD = 'correct1horse2battery';

    private PDO $pdo;
    private FixedClock $clock;
    private AccessControlService $access;
    private AuthService $authService;
    private CsrfGuard $csrf;
    private RegistrationController $controller;

    protected function setUp(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_sqlite is not loaded.');
        }

        $this->pdo = SqliteAuthTables::connection();
        $this->clock = FixedClock::at('2025-03-15 09:00:00');

        $this->authService = new AuthService(
            new UserRepository($this->pdo),
            new DefaultPasswordPolicy(),
            $this->clock,
            PasswordHasher::forTests(),
            new SessionRepository($this->pdo),
            new AuditLogRepository($this->pdo),
        );

        $this->access = new AccessControlService($this->clock);
        $this->csrf = CsrfGuard::withMasterKey(str_repeat("\x2b", 32), $this->clock);

        $this->controller = new RegistrationController(
            $this->access,
            $this->authService,
            $this->csrf,
            $this->clock,
        );
    }

    private function getRequest(string $path = AccessControlService::REGISTER_PATH): Request
    {
        return Request::of('GET', $path);
    }

    private function postRequest(string $path, array $form): Request
    {
        return Request::of('POST', $path, form: $form);
    }

    private function userCount(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) AS c FROM users')->fetch(PDO::FETCH_ASSOC)['c'];
    }

    private function sessionCount(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) AS c FROM sessions')->fetch(PDO::FETCH_ASSOC)['c'];
    }

    public function testShowRendersTheFormWithACsrfTokenAndThePasswordPolicyDescription(): void
    {
        $response = $this->controller->show($this->getRequest());

        self::assertSame(200, $response->status());
        $html = $response->body();
        self::assertStringContainsString(CsrfGuard::FIELD_NAME, $html);
        self::assertStringContainsString(DefaultPasswordPolicy::MESSAGE, $html);
        self::assertStringContainsString('type="email"', $html);
        self::assertStringContainsString('type="password"', $html);
    }

    public function testSubmitWithAValidRegistrationSignsTheOwnerInAndRedirectsHome(): void
    {
        $token = $this->csrf->issueFor($this->getRequest());

        $request = $this->postRequest(AccessControlService::REGISTER_PATH, [
            CsrfGuard::FIELD_NAME => $token,
            AuthService::EMAIL_FIELD => 'roy@example.com',
            PasswordPolicy::FIELD => self::PASSWORD,
        ]);

        $response = $this->controller->submit($request);

        self::assertSame(302, $response->status());
        self::assertSame('/', $response->header('Location'));

        $setCookie = $response->header('Set-Cookie');
        self::assertNotNull($setCookie);
        self::assertStringStartsWith(SessionCookie::NAME . '=', $setCookie);
        self::assertStringContainsString('Secure', $setCookie);
        self::assertStringContainsString('HttpOnly', $setCookie);
        self::assertStringContainsString('SameSite=Strict', $setCookie);

        self::assertSame(1, $this->userCount());
        self::assertSame(1, $this->sessionCount(), 'registering signs the new owner in with one session');
    }

    public function testSubmitWithAnAlreadyRegisteredEmailRedisplaysTheFormWithTheSubmittedEmailPreserved(): void
    {
        self::assertTrue($this->authService->register('roy@example.com', self::PASSWORD)->isOk());

        $token = $this->csrf->issueFor($this->getRequest());
        $request = $this->postRequest(AccessControlService::REGISTER_PATH, [
            CsrfGuard::FIELD_NAME => $token,
            AuthService::EMAIL_FIELD => 'roy@example.com',
            PasswordPolicy::FIELD => 'another9valid8password',
        ]);

        $response = $this->controller->submit($request);
        $html = $response->body();

        self::assertSame(200, $response->status());
        self::assertStringContainsString(AuthService::EMAIL_TAKEN_MESSAGE, $html);
        self::assertStringContainsString('value="roy@example.com"', $html);
        self::assertSame(1, $this->userCount(), 'the existing account is untouched, and no second row is written');
        self::assertSame(0, $this->sessionCount());
    }

    public function testSubmitWithAPasswordFailingThePolicyRedisplaysTheFormWithThePolicyMessageAndNoWrite(): void
    {
        $token = $this->csrf->issueFor($this->getRequest());
        $request = $this->postRequest(AccessControlService::REGISTER_PATH, [
            CsrfGuard::FIELD_NAME => $token,
            AuthService::EMAIL_FIELD => 'roy@example.com',
            PasswordPolicy::FIELD => 'short',
        ]);

        $response = $this->controller->submit($request);
        $html = $response->body();

        self::assertSame(200, $response->status());
        self::assertStringContainsString(DefaultPasswordPolicy::MESSAGE, $html);
        self::assertStringContainsString('value="roy@example.com"', $html);
        self::assertStringNotContainsString('short', $html, 'the submitted password itself is never echoed back');
        self::assertSame(0, $this->userCount(), 'a rejected registration writes nothing');
        self::assertSame(0, $this->sessionCount());
    }
}
