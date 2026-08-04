<?php

declare(strict_types=1);

namespace Diary\Tests\Unit\Access;

use Diary\Access\PermissionMatrix;
use Diary\Access\Verdict;
use Diary\Auth\ContextRole;
use Diary\Support\OperationKind;
use PHPUnit\Framework\TestCase;

/**
 * The matrix is the whole of the authorisation rule, so it is pinned cell by cell
 * against the design's table (Requirements 2.6, 3.3, 5.6, 7.3, 7.5, 10.5).
 */
final class PermissionMatrixTest extends TestCase
{
    public function testEveryCellMatchesTheDesignTable(): void
    {
        $expected = [
            // kind, anonymous, viewer, owner
            [OperationKind::ViewAuthPage, Verdict::Allow, Verdict::Allow, Verdict::Allow],
            [OperationKind::ReadDiaryData, Verdict::RedirectToLogin, Verdict::Allow, Verdict::Allow],
            [OperationKind::WriteDiaryEntry, Verdict::RedirectToLogin, Verdict::Deny, Verdict::Allow],
            [OperationKind::WriteMilestone, Verdict::RedirectToLogin, Verdict::Deny, Verdict::Allow],
            [OperationKind::ManageAccess, Verdict::RedirectToLogin, Verdict::Deny, Verdict::Allow],
        ];

        foreach ($expected as [$kind, $anonymous, $viewer, $owner]) {
            self::assertSame($anonymous, PermissionMatrix::verdictFor($kind, ContextRole::Anonymous), $kind->value);
            self::assertSame($viewer, PermissionMatrix::verdictFor($kind, ContextRole::Viewer), $kind->value);
            self::assertSame($owner, PermissionMatrix::verdictFor($kind, ContextRole::Owner), $kind->value);
        }
    }

    public function testTheTableIsTotal(): void
    {
        foreach (OperationKind::cases() as $kind) {
            foreach (ContextRole::cases() as $role) {
                // A missing cell throws rather than defaulting, so reaching a verdict
                // for every pair is the completeness check.
                self::assertInstanceOf(
                    Verdict::class,
                    PermissionMatrix::verdictFor($kind, $role),
                    $kind->value . ' / ' . $role->value
                );
            }
        }

        self::assertCount(count(OperationKind::cases()), PermissionMatrix::table());
    }

    public function testAnOwnerContextMayDoEverything(): void
    {
        foreach (OperationKind::cases() as $kind) {
            self::assertTrue(PermissionMatrix::allows($kind, ContextRole::Owner), $kind->value);
        }
    }

    public function testAViewerContextMayDoNothingThatMutates(): void
    {
        foreach (OperationKind::cases() as $kind) {
            $verdict = PermissionMatrix::verdictFor($kind, ContextRole::Viewer);

            self::assertSame(
                $kind->isMutating() ? Verdict::Deny : Verdict::Allow,
                $verdict,
                $kind->value
            );
        }
    }

    public function testAnAnonymousRequestIsOnlyEverAllowedOrRedirected(): void
    {
        foreach (OperationKind::cases() as $kind) {
            $verdict = PermissionMatrix::verdictFor($kind, ContextRole::Anonymous);

            // Never a 403 while signed out: the answer is always "go and sign in"
            // unless the page is one of the two public ones (Requirement 2.6).
            self::assertSame(
                $kind === OperationKind::ViewAuthPage ? Verdict::Allow : Verdict::RedirectToLogin,
                $verdict,
                $kind->value
            );
        }
    }

    public function testAllowsAgreesWithTheVerdict(): void
    {
        foreach (OperationKind::cases() as $kind) {
            foreach (ContextRole::cases() as $role) {
                self::assertSame(
                    PermissionMatrix::verdictFor($kind, $role) === Verdict::Allow,
                    PermissionMatrix::allows($kind, $role),
                    $kind->value . ' / ' . $role->value
                );
            }
        }
    }
}
