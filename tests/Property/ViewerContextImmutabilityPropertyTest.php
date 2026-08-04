<?php

declare(strict_types=1);

namespace Diary\Tests\Property;

use Diary\Access\AccessControlService;
use Diary\Auth\AuditLogRepository;
use Diary\Auth\IpHasher;
use Diary\Auth\SecurityContext;
use Diary\Auth\Session;
use Diary\Auth\SessionId;
use Diary\Auth\UserId;
use Diary\Auth\UserRole;
use Diary\Support\FixedClock;
use Diary\Support\Operation;
use Diary\Support\OperationKind;
use Diary\Tests\Unit\Auth\SqliteAuthTables;
use Eris\Generator;
use Eris\TestTrait;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Property 1: A viewer context can never change stored state.
 *
 * Every mutating operation kind (create/update/delete a Diary_Entry or
 * Milestone, create/revoke a Viewer, delete the account) is tried against a
 * session whose *context role* is Viewer, while the underlying account row's
 * `role` column is varied independently between owner and viewer. That
 * independence is the point of Requirement 7.3: an Owner_Role user who signed
 * in to a viewer account is a viewer for the whole session, because
 * {@see SecurityContext} is built from the session's frozen role, never from
 * the account's current one.
 *
 * `authorise()` is the only thing under test here, so the snapshot it must
 * leave untouched is exactly the state that call can see: `users`, `sessions`
 * and `audit_log` (the service never opens a diary or milestone table). The
 * one permitted change is the denial row {@see AccessControlService::refuse()}
 * always writes - the property asserts that row is *exactly* the expected
 * one and that removing it leaves every table byte-for-byte as it was before
 * the request.
 *
 * Requirements: 3.3, 5.6, 7.3, 7.5, 10.5.
 */
final class ViewerContextImmutabilityPropertyTest extends TestCase
{
    use TestTrait;

    private const CLOCK_START = '2025-04-17 11:05:30';

    private const OWNER_ID = '01HZY000000000000000000001';
    private const VIEWER_ID = '01HZY000000000000000000002';

    /** @var array<string, OperationKind> operation factory name => the kind it produces */
    private const MUTATING_OPERATIONS = [
        'createDiaryEntry' => OperationKind::WriteDiaryEntry,
        'updateDiaryEntry' => OperationKind::WriteDiaryEntry,
        'deleteDiaryEntry' => OperationKind::WriteDiaryEntry,
        'createMilestone' => OperationKind::WriteMilestone,
        'updateMilestone' => OperationKind::WriteMilestone,
        'deleteMilestone' => OperationKind::WriteMilestone,
        'createViewer' => OperationKind::ManageAccess,
        'revokeViewer' => OperationKind::ManageAccess,
        'deleteAccount' => OperationKind::ManageAccess,
    ];

    private const TARGET_TYPE_FOR_KIND = [
        'write_diary_entry' => 'diary_entry',
        'write_milestone' => 'milestone',
        'manage_access' => 'account_access',
    ];

    protected function setUp(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_sqlite is not loaded.');
        }
    }

    // Feature: mental-health-diary, Property 1: A viewer context can never change stored state
    public function testAViewerContextCanNeverChangeStoredState(): void
    {
        $this->limitTo(100)
            ->forAll(
                self::mutatingOperationName(),
                self::requestedPath(),
                self::accountRole(),
                self::callerIpAddress()
            )
            ->then(function (
                string $operationName,
                ?string $requestedPath,
                UserRole $accountRole,
                ?string $ipAddress
            ): void {
                $pdo = SqliteAuthTables::connection();
                $this->insertUser($pdo, self::OWNER_ID, UserRole::Owner, self::OWNER_ID);
                // The account's own role varies; the session's context role below
                // never does. Requirement 7.3 is exactly the claim that only the
                // latter decides the outcome.
                $this->insertUser($pdo, self::VIEWER_ID, $accountRole, self::OWNER_ID);

                $clock = FixedClock::at(self::CLOCK_START);
                $access = new AccessControlService(
                    $clock,
                    new AuditLogRepository($pdo),
                    new IpHasher('viewer-immutability-property-key'),
                );

                $ctx = SecurityContext::forSession(new Session(
                    id: SessionId::fromString(str_repeat('b', 64)),
                    userId: UserId::fromString(self::VIEWER_ID),
                    contextRole: UserRole::Viewer,
                    dataOwnerId: UserId::fromString(self::OWNER_ID),
                    createdAt: $clock->now(),
                    lastActivityAt: $clock->now(),
                ));

                $kind = self::MUTATING_OPERATIONS[$operationName];
                self::assertTrue($kind->isMutating(), $operationName . ' must be a mutating kind');

                /** @var Operation $operation */
                $operation = Operation::{$operationName}($requestedPath);

                $before = self::snapshot($pdo);

                $decision = $access->authorise($ctx, $operation, $ipAddress);

                // Denied, with the read-only message, on every one of these.
                self::assertTrue($decision->isDenied(), $operationName);
                self::assertSame(403, $decision->status(), $operationName);
                self::assertSame(AccessControlService::READ_ONLY_MESSAGE, $decision->message());
                self::assertSame(AccessControlService::READ_ONLY_ERROR_CODE, $decision->errorCode());
                self::assertNull($decision->location(), 'a denial is not a redirect');

                $after = self::snapshot($pdo);

                // Nothing about the accounts or the sessions changed.
                self::assertSame($before['users'], $after['users'], 'the users table must be untouched');
                self::assertSame($before['sessions'], $after['sessions'], 'the sessions table must be untouched');

                // Exactly one new row in audit_log: the expected denial, and
                // nothing else.
                self::assertCount(
                    count($before['audit_log']) + 1,
                    $after['audit_log'],
                    'exactly one denial row must be written'
                );
                self::assertSame(
                    $before['audit_log'],
                    array_slice($after['audit_log'], 0, count($before['audit_log'])),
                    'every pre-existing audit_log row must be left exactly as it was'
                );

                $denialRow = $after['audit_log'][count($after['audit_log']) - 1];

                self::assertSame(self::VIEWER_ID, $denialRow['actor_user_id']);
                self::assertSame(
                    'viewer',
                    $denialRow['context_role'],
                    'the audited role is the session\'s context role, not the account\'s own role'
                );
                self::assertSame('operation_denied', $denialRow['action']);
                self::assertSame('denied', $denialRow['outcome']);
                self::assertSame(self::TARGET_TYPE_FOR_KIND[$kind->value], $denialRow['target_type']);
                self::assertSame(self::OWNER_ID, $denialRow['target_id']);
                self::assertSame(self::CLOCK_START, $denialRow['occurred_at']);

                if ($ipAddress === null) {
                    self::assertNull($denialRow['ip_hash']);
                } else {
                    self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', (string) $denialRow['ip_hash']);
                }

                // Nothing free-text - the operation's requested path least of all
                // - reaches the trail.
                $flattened = implode('|', array_map(static fn ($value): string => (string) $value, $denialRow));
                if ($requestedPath !== null && $requestedPath !== '') {
                    self::assertStringNotContainsString($requestedPath, $flattened);
                }
            });
    }

    /**
     * @return array{users: list<array<string, mixed>>, sessions: list<array<string, mixed>>, audit_log: list<array<string, mixed>>}
     */
    private static function snapshot(PDO $pdo): array
    {
        return [
            'users' => self::rows($pdo, 'SELECT * FROM users ORDER BY id'),
            'sessions' => self::rows($pdo, 'SELECT * FROM sessions ORDER BY id'),
            'audit_log' => SqliteAuthTables::auditLog($pdo),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function rows(PDO $pdo, string $sql): array
    {
        $statement = $pdo->query($sql);

        /** @var list<array<string, mixed>> $rows */
        $rows = $statement === false ? [] : $statement->fetchAll(PDO::FETCH_ASSOC);

        return $rows;
    }

    private function insertUser(PDO $pdo, string $id, UserRole $role, string $dataOwnerId): void
    {
        $statement = $pdo->prepare(
            'INSERT INTO users (
                id, email_normalized, email_display, password_hash, role, data_owner_id,
                status, failed_login_count, locked_until, deletion_requested_at, created_at, updated_at
            ) VALUES (
                :id, :email_normalized, :email_display, :password_hash, :role, :data_owner_id,
                :status, 0, NULL, NULL, :created_at, :updated_at
            )'
        );
        $statement->execute([
            ':id' => $id,
            ':email_normalized' => strtolower($id) . '@example.com',
            ':email_display' => $id . '@example.com',
            ':password_hash' => 'unused-hash',
            ':role' => $role->value,
            ':data_owner_id' => $dataOwnerId,
            ':status' => 'active',
            ':created_at' => self::CLOCK_START,
            ':updated_at' => self::CLOCK_START,
        ]);
    }

    /**
     * The name of one of the nine mutating {@see Operation} factories.
     */
    private static function mutatingOperationName(): \Eris\Generator
    {
        return Generator\elements(array_keys(self::MUTATING_OPERATIONS));
    }

    /**
     * The path a request was heading to, or none. It never affects the
     * decision, but it must never leak into the audit trail.
     */
    private static function requestedPath(): \Eris\Generator
    {
        return Generator\oneOf(
            Generator\constant(null),
            Generator\map(
                static fn (string $segment): string => '/' . $segment,
                Generator\elements(['entry', 'milestones', 'viewers', 'account', 'diary/2025-04-17'])
            )
        );
    }

    /**
     * The account's own `role` column - independent of the session's context
     * role, which is fixed at Viewer for this property.
     */
    private static function accountRole(): \Eris\Generator
    {
        return Generator\elements([UserRole::Owner, UserRole::Viewer]);
    }

    /**
     * A caller address, or none - hashed on a denial only when present.
     */
    private static function callerIpAddress(): \Eris\Generator
    {
        return Generator\oneOf(
            Generator\constant(null),
            Generator\map(
                static fn (array $octets): string => implode('.', $octets),
                Generator\tuple(
                    Generator\choose(1, 255),
                    Generator\choose(0, 255),
                    Generator\choose(0, 255),
                    Generator\choose(1, 254)
                )
            )
        );
    }
}
