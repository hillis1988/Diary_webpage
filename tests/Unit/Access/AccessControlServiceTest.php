<?php

declare(strict_types=1);

namespace Diary\Tests\Unit\Access;

use Diary\Access\AccessControlService;
use Diary\Access\AuthPaths;
use Diary\Access\Decision;
use Diary\Access\DecisionOutcome;
use Diary\Auth\AuditLogRepository;
use Diary\Auth\IpHasher;
use Diary\Auth\SecurityContext;
use Diary\Auth\Session;
use Diary\Auth\SessionId;
use Diary\Auth\UserId;
use Diary\Auth\UserRole;
use Diary\Storage\ConnectionFactory;
use Diary\Support\FixedClock;
use Diary\Support\Operation;
use Diary\Support\OperationKind;
use Diary\Support\Ulid;
use Diary\Tests\Unit\Auth\SqliteAuthTables;
use LogicException;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * The single enforcement point: anonymous requests are redirected with their
 * intended path intact (Requirement 2.6), a viewer context cannot mutate anything
 * (Requirements 3.3, 5.6, 7.3, 7.5, 10.5), and owner scoping comes from the session
 * alone (Requirement 4.4).
 */
final class AccessControlServiceTest extends TestCase
{
    private const OWNER_ID = '01HZY000000000000000000001';
    private const VIEWER_ID = '01HZY000000000000000000002';

    private FixedClock $clock;
    private PDO $pdo;
    private AccessControlService $access;

    protected function setUp(): void
    {
        $this->clock = FixedClock::at('2024-03-01 09:00:00');
        $this->pdo = ConnectionFactory::fromDsn('sqlite::memory:');
        SqliteAuthTables::createAuditLog($this->pdo);

        $this->access = new AccessControlService(
            $this->clock,
            new AuditLogRepository($this->pdo),
            new IpHasher('ip-hash-key'),
        );
    }

    public function testAnonymousReadIsRedirectedToLoginKeepingTheIntendedPath(): void
    {
        $decision = $this->access->authorise(
            SecurityContext::anonymous(),
            Operation::readDiaryData('calendar.view', '/calendar?month=2024-03'),
        );

        self::assertSame(DecisionOutcome::RedirectToLogin, $decision->outcome());
        self::assertSame(302, $decision->status());
        self::assertSame('/calendar?month=2024-03', $decision->intendedPath());
        self::assertSame(
            '/login?next=' . rawurlencode('/calendar?month=2024-03'),
            $decision->location(),
        );
        self::assertNull($decision->message(), 'a redirect carries no error message');
    }

    public function testAnonymousMutationIsRedirectedRatherThanRefused(): void
    {
        // Signed out, the answer is always "sign in first" - a 403 would tell a
        // stranger that the operation exists.
        $operations = [
            Operation::createDiaryEntry('/entry'),
            Operation::createMilestone('/milestones'),
            Operation::deleteAccount('/account'),
        ];

        foreach ($operations as $operation) {
            $decision = $this->access->authorise(SecurityContext::anonymous(), $operation);

            self::assertTrue($decision->isRedirectToLogin(), $operation->action());
            self::assertSame('/login?next=' . rawurlencode((string) $operation->requestedPath()), $decision->location());
        }
    }

    public function testAnonymousMayReachLoginAndRegistration(): void
    {
        foreach ([Operation::viewLogin('/login'), Operation::viewRegister('/register')] as $operation) {
            self::assertTrue(
                $this->access->authorise(SecurityContext::anonymous(), $operation)->isAllowed(),
                $operation->action(),
            );
        }

        self::assertTrue(AccessControlService::isPublicPath('/login'));
        self::assertTrue(AccessControlService::isPublicPath('/register'));
        self::assertTrue(AccessControlService::isPublicPath('/login?next=%2Fcalendar'));
        self::assertFalse(AccessControlService::isPublicPath('/'));
        self::assertFalse(AccessControlService::isPublicPath('/calendar'));
        self::assertFalse(AccessControlService::isPublicPath('/login/../entry'));
    }

    public function testARedirectRefusesToCarryAnythingButALocalPath(): void
    {
        $unsafe = [
            'https://evil.example/steal',
            '//evil.example/steal',
            '/\\evil.example',
            "/calendar\r\nSet-Cookie: a=b",
            'calendar',
            '',
            '/login',
        ];

        foreach ($unsafe as $path) {
            $decision = Decision::redirectToLogin($path);

            self::assertNull($decision->intendedPath(), $path);
            self::assertSame('/login', $decision->location(), $path);
        }
    }

    public function testAViewerContextMayRead(): void
    {
        $decision = $this->access->authorise(
            $this->viewerContext(),
            Operation::readDiaryData('summary.view', '/summary'),
        );

        self::assertTrue($decision->isAllowed());
        self::assertSame([], SqliteAuthTables::auditLog($this->pdo), 'an allowed read is not a security event');
    }

    public function testEveryMutationFromAViewerContextIsRefusedWithTheReadOnlyMessage(): void
    {
        $mutations = [
            Operation::createDiaryEntry('/entry'),
            Operation::updateDiaryEntry('/entry'),
            Operation::deleteDiaryEntry('/entry'),
            Operation::createMilestone('/milestones'),
            Operation::updateMilestone('/milestones'),
            Operation::deleteMilestone('/milestones'),
            Operation::createViewer('/viewers'),
            Operation::revokeViewer('/viewers'),
            Operation::deleteAccount('/account'),
        ];

        foreach ($mutations as $operation) {
            $decision = $this->access->authorise($this->viewerContext(), $operation, '203.0.113.7');

            self::assertTrue($decision->isDenied(), $operation->action());
            self::assertSame(403, $decision->status(), $operation->action());
            self::assertSame('This account has read-only access', $decision->message());
            self::assertSame(AccessControlService::READ_ONLY_MESSAGE, $decision->message());
            self::assertSame(AccessControlService::READ_ONLY_ERROR_CODE, $decision->errorCode());
            self::assertNull($decision->location(), 'a denial is not a redirect');

            $result = $decision->toResult();
            self::assertTrue($result->isFailure());
            self::assertSame(AccessControlService::READ_ONLY_MESSAGE, $result->message());
        }

        self::assertCount(count($mutations), SqliteAuthTables::auditLog($this->pdo), 'every denial is logged');
    }

    public function testADeniedOperationIsLoggedWithIdentifiersOnly(): void
    {
        $this->access->authorise($this->viewerContext(), Operation::createDiaryEntry('/entry'), '203.0.113.7');

        $rows = SqliteAuthTables::auditLog($this->pdo);
        self::assertCount(1, $rows);

        $row = $rows[0];

        self::assertSame('viewer', $row['context_role']);
        self::assertSame('operation_denied', $row['action']);
        self::assertSame('denied', $row['outcome']);
        self::assertSame(self::VIEWER_ID, $row['actor_user_id']);
        self::assertSame(self::OWNER_ID, $row['target_id'], 'the owner whose data was aimed at');
        self::assertSame('diary_entry', $row['target_type'], 'a record kind, never its content');
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', (string) $row['ip_hash']);
        self::assertSame('2024-03-01 09:00:00', $row['occurred_at']);
        self::assertTrue(Ulid::isValid((string) $row['id']));

        // Nothing free-text reaches the trail: not the caller address, and not the
        // operation label, let alone anything from a diary entry.
        $flattened = implode('|', array_map(static fn ($value): string => (string) $value, $row));
        self::assertStringNotContainsString('203.0.113.7', $flattened);
        self::assertStringNotContainsString('diary_entry.create', $flattened);
    }

    public function testAnOwnerContextMayPerformEveryKind(): void
    {
        $context = $this->ownerContext();

        foreach (OperationKind::cases() as $kind) {
            $decision = $this->access->authorise($context, Operation::of($kind, 'test.' . $kind->value));

            self::assertTrue($decision->isAllowed(), $kind->value);
        }

        self::assertSame([], SqliteAuthTables::auditLog($this->pdo));
    }

    public function testADenialDoesNotDependOnAnAuditLogBeingConfigured(): void
    {
        $withoutLog = new AccessControlService($this->clock);

        $decision = $withoutLog->authorise($this->viewerContext(), Operation::createMilestone('/milestones'));

        self::assertTrue($decision->isDenied());
        self::assertSame(AccessControlService::READ_ONLY_MESSAGE, $decision->message());
    }

    public function testResolveDataOwnerReturnsTheSessionsDataOwnerNotTheSignedInUser(): void
    {
        $viewer = $this->access->resolveDataOwner($this->viewerContext());

        self::assertSame(self::OWNER_ID, $viewer->toString());
        self::assertNotSame(self::VIEWER_ID, $viewer->toString());

        $owner = $this->access->resolveDataOwner($this->ownerContext());

        self::assertSame(self::OWNER_ID, $owner->toString());
        self::assertTrue($owner->equals($viewer), 'both sessions read exactly one owner\'s data');
    }

    public function testResolveDataOwnerRefusesAnAnonymousContext(): void
    {
        // Returning null here would leave a repository query unscoped.
        $this->expectException(LogicException::class);

        $this->access->resolveDataOwner(SecurityContext::anonymous());
    }

    public function testTheLoginPathsAreStatedOnce(): void
    {
        self::assertSame(AuthPaths::LOGIN, AccessControlService::LOGIN_PATH);
        self::assertSame(AuthPaths::REGISTER, AccessControlService::REGISTER_PATH);
        self::assertSame('/login', AccessControlService::LOGIN_PATH);
        self::assertSame('/register', AccessControlService::REGISTER_PATH);
    }

    private function ownerContext(): SecurityContext
    {
        return SecurityContext::forSession($this->session(self::OWNER_ID, UserRole::Owner));
    }

    /**
     * A viewer session: a different user id from the owner, reading the owner's data.
     */
    private function viewerContext(): SecurityContext
    {
        return SecurityContext::forSession($this->session(self::VIEWER_ID, UserRole::Viewer));
    }

    private function session(string $userId, UserRole $role): Session
    {
        return new Session(
            id: SessionId::fromString(str_repeat('a', 64)),
            userId: UserId::fromString($userId),
            contextRole: $role,
            dataOwnerId: UserId::fromString(self::OWNER_ID),
            createdAt: $this->clock->now(),
            lastActivityAt: $this->clock->now(),
        );
    }
}
