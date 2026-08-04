<?php

declare(strict_types=1);

namespace Diary\Tests\Unit\Support;

use Diary\Support\Operation;
use Diary\Support\OperationKind;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Authorisation decides on operation kind alone, and the action label is what
 * lands in audit_log, so the mapping from factory to kind and label is pinned
 * here (Requirement 2.5).
 */
final class OperationTest extends TestCase
{
    public function testEveryMutatingKindIsMarkedAsSuch(): void
    {
        self::assertFalse(OperationKind::ViewAuthPage->isMutating());
        self::assertFalse(OperationKind::ReadDiaryData->isMutating());
        self::assertTrue(OperationKind::WriteDiaryEntry->isMutating());
        self::assertTrue(OperationKind::WriteMilestone->isMutating());
        self::assertTrue(OperationKind::ManageAccess->isMutating());
    }

    public function testFactoriesCarryTheRightKindAndAuditLabel(): void
    {
        $cases = [
            [Operation::viewLogin(), OperationKind::ViewAuthPage, 'auth.view_login'],
            [Operation::viewRegister(), OperationKind::ViewAuthPage, 'auth.view_register'],
            [Operation::readDiaryData('calendar.view'), OperationKind::ReadDiaryData, 'calendar.view'],
            [Operation::createDiaryEntry(), OperationKind::WriteDiaryEntry, 'diary_entry.create'],
            [Operation::updateDiaryEntry(), OperationKind::WriteDiaryEntry, 'diary_entry.update'],
            [Operation::deleteDiaryEntry(), OperationKind::WriteDiaryEntry, 'diary_entry.delete'],
            [Operation::createMilestone(), OperationKind::WriteMilestone, 'milestone.create'],
            [Operation::updateMilestone(), OperationKind::WriteMilestone, 'milestone.update'],
            [Operation::deleteMilestone(), OperationKind::WriteMilestone, 'milestone.delete'],
            [Operation::createViewer(), OperationKind::ManageAccess, 'viewer.create'],
            [Operation::revokeViewer(), OperationKind::ManageAccess, 'viewer.revoke'],
            [Operation::deleteAccount(), OperationKind::ManageAccess, 'account.delete'],
        ];

        foreach ($cases as [$operation, $kind, $action]) {
            self::assertSame($kind, $operation->kind(), $action);
            self::assertSame($action, $operation->action());
            self::assertSame($action, (string) $operation);
            self::assertSame($kind->isMutating(), $operation->isMutating(), $action);
        }
    }

    public function testTheRequestedPathIsCarriedForTheAnonymousRedirect(): void
    {
        $operation = Operation::readDiaryData('summary.view', '/summary?from=2024-02-01');

        self::assertSame('/summary?from=2024-02-01', $operation->requestedPath());

        $rebound = $operation->withRequestedPath('/calendar');

        self::assertSame('/calendar', $rebound->requestedPath());
        self::assertSame('summary.view', $rebound->action());
        self::assertSame(OperationKind::ReadDiaryData, $rebound->kind());
        self::assertSame('/summary?from=2024-02-01', $operation->requestedPath(), 'the original is untouched');
    }

    public function testTheRequestedPathIsOptional(): void
    {
        self::assertNull(Operation::createDiaryEntry()->requestedPath());
    }

    public function testAnOperationWithoutAnActionLabelIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Operation::of(OperationKind::ReadDiaryData, '');
    }
}
