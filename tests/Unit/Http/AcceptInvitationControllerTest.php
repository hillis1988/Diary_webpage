<?php

declare(strict_types=1);

namespace Diary\Tests\Unit\Http;

use Diary\Access\AccessControlService;
use Diary\Access\AuthPaths;
use Diary\Auth\AuditLogRepository;
use Diary\Auth\AuthService;
use Diary\Auth\DefaultPasswordPolicy;
use Diary\Auth\InvitationToken;
use Diary\Auth\PasswordHasher;
use Diary\Auth\PasswordPolicy;
use Diary\Auth\SessionCookie;
use Diary\Auth\SessionRepository;
use Diary\Auth\UserAccount;
use Diary\Auth\UserId;
use Diary\Auth\EmailAddress;
use Diary\Auth\UserRepository;
use Diary\Http\AcceptInvitationController;
use Diary\Http\CsrfGuard;
use Diary\Http\Request;
use Diary\Support\FixedClock;
use Diary\Support\Ulid;
use PDO;
use PHPUnit\Framework\TestCase;
use Diary\Tests\Unit\Auth\SqliteAuthTables;

/**
 * The invitation-acceptance page and controller (Requirement 7.1).
 *
 * Uses a real {@see AuthService} against an in-memory SQLite schema, following
 * the pattern in tests/Unit/Http/RegistrationControllerTest.php: what matters
 * here is what ends up (or does not end up) in the `users` and `sessions`
 * tables, not a mocked collaborator.
 */
final class AcceptInvitationControllerTest extends TestCase
{
    private const VIEWER_EMAIL = 'viewer@example.com';

    private PDO $pdo;
    private FixedClock $clock;
    private AccessControlService $access;
    private AuthService $authService;
    private UserRepository $users;
    private CsrfGuard $csrf;
    private AcceptInvitationController $controller;

    protected function setUp(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_sqlite is not loaded.');
        }

        $this->pdo = SqliteAuthTables::connection();
        $this->clock = FixedClock::at('2025-03-15 09:00:00');
        $this->users = new UserRepository($this->pdo);

        $this->authService = new AuthService(
            $this->users,
            new DefaultPasswordPolicy(),
            $this->clock,
            PasswordHasher::forTests(),
            new SessionRepository($this->pdo),
            new AuditLogRepository($this->pdo),
        );

        $this->access = new AccessControlService($this->clock);
        $this->csrf = CsrfGuard::withMasterKey(str_repeat("\x2b", 32), $this->clock);

        $this->controller = new AcceptInvitationController(
            $this->access,
            $this->authService,
            $this->users,
            $this->csrf,
            $this->clock,
        );
    }

    private function getRequest(string $path): Request
    {
        [$pathOnly, $query] = self::splitQuery($path);

        return Request::of('GET', $pathOnly, query: $query, queryString: parse_url($path, PHP_URL_QUERY) ?? '');
    }

    /**
     * @param array<string, string> $form
     */
    private function postRequest(array $form): Request
    {
        return Request::of('POST', AccessControlService::ACCEPT_INVITATION_PATH, form: $form);
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

    private function sessionCount(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) AS c FROM sessions')->fetch(PDO::FETCH_ASSOC)['c'];
    }

    /**
     * Invite a viewer directly against the account layer (there is no invite
     * controller dependency here), returning the raw invitation token.
     */
    private function inviteViewer(): string
    {
        $token = InvitationToken::generate();
        $ownerId = UserId::fromString(Ulid::generate($this->clock));
        $viewerId = UserId::fromString(Ulid::generate($this->clock));

        $account = UserAccount::newInvitedViewer(
            $viewerId,
            EmailAddress::fromInput(self::VIEWER_EMAIL),
            $ownerId,
            $token->hash(),
            $this->clock->now()->modify('+7 days'),
            $this->clock->now(),
        );

        // The owner row the viewer's data_owner_id points at must exist too,
        // for the foreign key on sessions to be satisfiable once signed in.
        $owner = UserAccount::newOwner(
            $ownerId,
            EmailAddress::fromInput('owner@example.com'),
            PasswordHasher::forTests()->hash('irrelevant12password'),
            $this->clock->now(),
        );
        $this->users->insert($owner);
        $this->users->insert($account);

        return $token->value();
    }

    public function testShowWithAValidTokenRendersTheSetPasswordFormCarryingTheToken(): void
    {
        $token = $this->inviteViewer();

        $response = $this->controller->show($this->getRequest(
            AccessControlService::ACCEPT_INVITATION_PATH . '?' . AcceptInvitationController::TOKEN_FIELD . '=' . $token
        ));

        self::assertSame(200, $response->status());
        $html = $response->body();
        self::assertStringContainsString(CsrfGuard::FIELD_NAME, $html);
        self::assertStringContainsString(DefaultPasswordPolicy::MESSAGE, $html);
        self::assertStringContainsString('value="' . $token . '"', $html);
        self::assertStringContainsString('type="password"', $html);
    }

    public function testShowWithAMissingTokenShowsTheInvitationInvalidMessageInsteadOfAForm(): void
    {
        $response = $this->controller->show($this->getRequest(AccessControlService::ACCEPT_INVITATION_PATH));

        self::assertSame(200, $response->status());
        $html = $response->body();
        self::assertStringContainsString(AuthService::INVITATION_INVALID_MESSAGE, $html);
        self::assertStringNotContainsString('type="password"', $html);
    }

    public function testShowWithAMalformedTokenShowsTheInvitationInvalidMessage(): void
    {
        $response = $this->controller->show($this->getRequest(
            AccessControlService::ACCEPT_INVITATION_PATH . '?' . AcceptInvitationController::TOKEN_FIELD . '=not-a-token'
        ));

        self::assertSame(200, $response->status());
        $html = $response->body();
        self::assertStringContainsString(AuthService::INVITATION_INVALID_MESSAGE, $html);
        self::assertStringNotContainsString('type="password"', $html);
    }

    public function testSubmitWithAValidTokenAndPasswordSignsTheViewerInAndRedirectsHome(): void
    {
        $token = $this->inviteViewer();
        $csrfToken = $this->csrf->issueFor($this->getRequest(AccessControlService::ACCEPT_INVITATION_PATH));

        $response = $this->controller->submit($this->postRequest([
            CsrfGuard::FIELD_NAME => $csrfToken,
            AcceptInvitationController::TOKEN_FIELD => $token,
            PasswordPolicy::FIELD => 'brandnew12password',
        ]));

        self::assertSame(302, $response->status());
        self::assertSame('/', $response->header('Location'));

        $setCookie = $response->header('Set-Cookie');
        self::assertNotNull($setCookie);
        self::assertStringStartsWith(SessionCookie::NAME . '=', $setCookie);

        self::assertSame(1, $this->sessionCount(), 'accepting the invitation signs the viewer in with one session');

        $viewer = $this->users->findByEmail(self::VIEWER_EMAIL);
        self::assertNotNull($viewer);
        self::assertSame('active', $viewer->status->value);
    }

    public function testSubmitWithAnInvalidTokenShowsTheInvitationInvalidMessage(): void
    {
        $csrfToken = $this->csrf->issueFor($this->getRequest(AccessControlService::ACCEPT_INVITATION_PATH));

        $response = $this->controller->submit($this->postRequest([
            CsrfGuard::FIELD_NAME => $csrfToken,
            AcceptInvitationController::TOKEN_FIELD => str_repeat('a', 64),
            PasswordPolicy::FIELD => 'brandnew12password',
        ]));

        self::assertSame(200, $response->status());
        self::assertStringContainsString(AuthService::INVITATION_INVALID_MESSAGE, $response->body());
        self::assertSame(0, $this->sessionCount());
    }

    public function testSubmitWithAPasswordFailingThePolicyRedisplaysTheFormWithThePolicyMessageAndNoWrite(): void
    {
        $token = $this->inviteViewer();
        $csrfToken = $this->csrf->issueFor($this->getRequest(AccessControlService::ACCEPT_INVITATION_PATH));

        $response = $this->controller->submit($this->postRequest([
            CsrfGuard::FIELD_NAME => $csrfToken,
            AcceptInvitationController::TOKEN_FIELD => $token,
            PasswordPolicy::FIELD => 'short',
        ]));

        $html = $response->body();

        self::assertSame(200, $response->status());
        self::assertStringContainsString(DefaultPasswordPolicy::MESSAGE, $html);
        self::assertStringContainsString('value="' . $token . '"', $html);
        self::assertStringNotContainsString('short', $html, 'the submitted password itself is never echoed back');
        self::assertSame(0, $this->sessionCount());

        $viewer = $this->users->findByEmail(self::VIEWER_EMAIL);
        self::assertNotNull($viewer);
        self::assertSame('invited', $viewer->status->value, 'a rejected password leaves the invitation still usable');
    }

    public function testThePathIsPublicAlongsideLoginAndRegister(): void
    {
        self::assertTrue(AuthPaths::isPublic(AccessControlService::ACCEPT_INVITATION_PATH));
        self::assertTrue(AccessControlService::isPublicPath(AccessControlService::ACCEPT_INVITATION_PATH));
    }
}
