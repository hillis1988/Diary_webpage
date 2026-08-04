<?php

declare(strict_types=1);

namespace Diary\Tests\Unit\Http;

use Diary\Access\AccessControlService;
use Diary\Access\AuthPaths;
use Diary\Auth\AuditLogRepository;
use Diary\Auth\AuthService;
use Diary\Auth\DefaultPasswordPolicy;
use Diary\Auth\PasswordHasher;
use Diary\Auth\PasswordPolicy;
use Diary\Auth\SessionCookie;
use Diary\Auth\SessionRepository;
use Diary\Auth\UserRepository;
use Diary\Http\CsrfGuard;
use Diary\Http\LoginController;
use Diary\Http\Request;
use Diary\Support\FixedClock;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Diary\Tests\Unit\Auth\SqliteAuthTables;

/**
 * The login page and controller (Requirements 2.1, 2.2, 2.3, 2.6).
 *
 * Uses a real {@see AuthService} against an in-memory SQLite schema, following
 * the pattern in tests/Unit/Http/RegistrationControllerTest.php and
 * tests/Unit/Http/AcceptInvitationControllerTest.php: what matters here is what
 * ends up (or does not end up) in the `sessions` table, and what the `next`
 * redirect round trip carries, not a mocked collaborator.
 */
final class LoginControllerTest extends TestCase
{
    private const EMAIL = 'roy@example.com';

    private const PASSWORD = 'correct1horse2battery';

    private PDO $pdo;
    private FixedClock $clock;
    private AccessControlService $access;
    private AuthService $authService;
    private CsrfGuard $csrf;
    private LoginController $controller;

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

        $this->controller = new LoginController(
            $this->access,
            $this->authService,
            $this->csrf,
            $this->clock,
        );

        self::assertTrue($this->authService->register(self::EMAIL, self::PASSWORD)->isOk());
    }

    /**
     * @return array{0: string, 1: array<string, string>}
     */
    private static function splitQuery(string $path): array
    {
        $parts = explode('?', $path, 2);
        $query = [];
        if (isset($parts[1])) {
            parse_str($parts[1], $query);
        }

        /** @var array<string, string> $query */
        return [$parts[0], $query];
    }

    private function getRequest(string $path = AuthPaths::LOGIN): Request
    {
        [$pathOnly, $query] = self::splitQuery($path);

        return Request::of('GET', $pathOnly, query: $query, queryString: parse_url($path, PHP_URL_QUERY) ?? '');
    }

    /**
     * @param array<string, string> $form
     */
    private function postRequest(array $form): Request
    {
        return Request::of('POST', AuthPaths::LOGIN, form: $form);
    }

    private function sessionCount(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) AS c FROM sessions')->fetch(PDO::FETCH_ASSOC)['c'];
    }

    public function testShowRendersTheFormWithACsrfToken(): void
    {
        $response = $this->controller->show($this->getRequest());

        self::assertSame(200, $response->status());
        $html = $response->body();
        self::assertStringContainsString(CsrfGuard::FIELD_NAME, $html);
        self::assertStringContainsString('type="email"', $html);
        self::assertStringContainsString('type="password"', $html);
    }

    public function testShowWithASafeNextQueryParameterPreservesItAsAHiddenField(): void
    {
        $response = $this->controller->show($this->getRequest(AuthPaths::LOGIN . '?next=%2Fdiary'));

        self::assertSame(200, $response->status());
        $html = $response->body();
        self::assertStringContainsString(
            '<input type="hidden" name="' . LoginController::NEXT_FIELD . '" value="/diary">',
            $html,
        );
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function unsafeNextPaths(): array
    {
        return [
            'absolute URL' => ['https://evil.example'],
            'protocol-relative URL' => ['//evil.example'],
        ];
    }

    #[DataProvider('unsafeNextPaths')]
    public function testShowWithAnUnsafeNextQueryParameterDropsIt(string $unsafeNext): void
    {
        $response = $this->controller->show($this->getRequest(
            AuthPaths::LOGIN . '?next=' . rawurlencode($unsafeNext)
        ));

        self::assertSame(200, $response->status());
        $html = $response->body();
        self::assertStringNotContainsString('name="' . LoginController::NEXT_FIELD . '"', $html);
        self::assertStringNotContainsString($unsafeNext, $html);
    }

    public function testSubmitWithCorrectCredentialsAndNoNextSignsInAndRedirectsHome(): void
    {
        $token = $this->csrf->issueFor($this->getRequest());

        $response = $this->controller->submit($this->postRequest([
            CsrfGuard::FIELD_NAME => $token,
            LoginController::EMAIL_FIELD => self::EMAIL,
            LoginController::PASSWORD_FIELD => self::PASSWORD,
        ]));

        self::assertSame(302, $response->status());
        self::assertSame('/', $response->header('Location'));

        $setCookie = $response->header('Set-Cookie');
        self::assertNotNull($setCookie);
        self::assertStringStartsWith(SessionCookie::NAME . '=', $setCookie);
        self::assertStringContainsString('Secure', $setCookie);
        self::assertStringContainsString('HttpOnly', $setCookie);
        self::assertStringContainsString('SameSite=Strict', $setCookie);

        self::assertSame(1, $this->sessionCount(), 'signing in starts exactly one session');
    }

    public function testSubmitWithCorrectCredentialsAndASafeNextRedirectsToTheSanitisedNextPath(): void
    {
        $token = $this->csrf->issueFor($this->getRequest());

        $response = $this->controller->submit($this->postRequest([
            CsrfGuard::FIELD_NAME => $token,
            LoginController::EMAIL_FIELD => self::EMAIL,
            LoginController::PASSWORD_FIELD => self::PASSWORD,
            LoginController::NEXT_FIELD => '/diary',
        ]));

        self::assertSame(302, $response->status());
        self::assertSame('/diary', $response->header('Location'));

        $setCookie = $response->header('Set-Cookie');
        self::assertNotNull($setCookie);
        self::assertStringStartsWith(SessionCookie::NAME . '=', $setCookie);

        self::assertSame(1, $this->sessionCount());
    }

    public function testSubmitWithCorrectCredentialsAndAnUnsafeNextRedirectsHomeInstead(): void
    {
        $token = $this->csrf->issueFor($this->getRequest());

        $response = $this->controller->submit($this->postRequest([
            CsrfGuard::FIELD_NAME => $token,
            LoginController::EMAIL_FIELD => self::EMAIL,
            LoginController::PASSWORD_FIELD => self::PASSWORD,
            LoginController::NEXT_FIELD => 'https://evil.example',
        ]));

        self::assertSame(302, $response->status());
        self::assertSame('/', $response->header('Location'));
    }

    public function testSubmitWithAWrongPasswordRedisplaysTheFormWithTheIncorrectCredentialsMessageAndTheEmailPreserved(): void
    {
        $token = $this->csrf->issueFor($this->getRequest());

        $response = $this->controller->submit($this->postRequest([
            CsrfGuard::FIELD_NAME => $token,
            LoginController::EMAIL_FIELD => self::EMAIL,
            LoginController::PASSWORD_FIELD => 'totally-wrong-password',
        ]));

        $html = $response->body();

        self::assertSame(200, $response->status());
        self::assertStringContainsString(AuthService::INCORRECT_CREDENTIALS_MESSAGE, $html);
        self::assertStringContainsString('value="' . self::EMAIL . '"', $html);
        self::assertStringNotContainsString('totally-wrong-password', $html, 'the submitted password is never echoed back');
        self::assertSame(0, $this->sessionCount(), 'a wrong password creates no session row');
    }

    public function testSubmitWithAnAccountLockedByFiveConsecutiveFailuresRedisplaysTheFormWithTheAccountLockedMessage(): void
    {
        // Five consecutive failures lock the account (Requirement 2.3), driven
        // straight through AuthService the same way the lockout property test
        // does, so the sixth attempt below meets a lock already in place.
        for ($attempt = 1; $attempt <= AuthService::MAX_FAILED_ATTEMPTS; $attempt++) {
            $this->authService->authenticate(self::EMAIL, 'wrong-password-' . $attempt, $this->clock->now());
        }

        $token = $this->csrf->issueFor($this->getRequest());

        $response = $this->controller->submit($this->postRequest([
            CsrfGuard::FIELD_NAME => $token,
            LoginController::EMAIL_FIELD => self::EMAIL,
            LoginController::PASSWORD_FIELD => self::PASSWORD,
        ]));

        $html = $response->body();

        self::assertSame(200, $response->status());
        self::assertStringContainsString(AuthService::ACCOUNT_LOCKED_MESSAGE, $html);
        self::assertSame(0, $this->sessionCount(), 'a live lock starts no session even with the correct password');
    }

    public function testSubmitWithAnEmailThatHasNoAccountGetsTheSameIncorrectCredentialsMessage(): void
    {
        $token = $this->csrf->issueFor($this->getRequest());

        $response = $this->controller->submit($this->postRequest([
            CsrfGuard::FIELD_NAME => $token,
            LoginController::EMAIL_FIELD => 'nobody@example.com',
            LoginController::PASSWORD_FIELD => 'whatever-password',
        ]));

        $html = $response->body();

        self::assertSame(200, $response->status());
        self::assertStringContainsString(AuthService::INCORRECT_CREDENTIALS_MESSAGE, $html);
        self::assertStringNotContainsString(AuthService::ACCOUNT_LOCKED_MESSAGE, $html);
        self::assertSame(0, $this->sessionCount());
    }
}
