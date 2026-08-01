<?php

declare(strict_types=1);

namespace Diary\Access;

use Diary\Auth\ContextRole;
use Diary\Support\OperationKind;
use LogicException;

/**
 * The permission matrix, as one declarative table and nothing else.
 *
 * | Operation kind        | anonymous | viewer context | owner context |
 * | --------------------- | --------- | -------------- | ------------- |
 * | View login / register | allow     | allow          | allow         |
 * | Read diary data       | redirect  | allow          | allow         |
 * | Write Diary_Entry     | redirect  | **deny**       | allow         |
 * | Write Milestone       | redirect  | **deny**       | allow         |
 * | Manage access         | redirect  | **deny**       | allow         |
 *
 * There is no logic here beyond the lookup: no `if` on a path, no special case for
 * a particular handler, and no second place where a permission is decided. That is
 * the point. Requirements 3.3, 5.6, 7.3, 7.5 and 10.5 all reduce to the three
 * `deny` cells in the viewer column, and Requirement 2.6 to the four `redirect`
 * cells in the anonymous column, so a new route cannot quietly acquire a rule of
 * its own - it has to name one of the five kinds and take that row's answer.
 *
 * The table is total: every kind is spelled out against every role, and
 * {@see verdictFor()} throws rather than guessing if a cell is ever missing.
 */
final class PermissionMatrix
{
    /** @var array<string, array<string, Verdict>>|null the table, built once */
    private static ?array $table = null;

    private function __construct()
    {
    }

    /**
     * The one lookup. Keyed on operation kind and context role - never on a path,
     * a user id, or the account's current role (Requirement 7.3: the session's
     * frozen role is what counts).
     */
    public static function verdictFor(OperationKind $kind, ContextRole $role): Verdict
    {
        $verdict = self::table()[$kind->value][$role->value] ?? null;

        if ($verdict === null) {
            // Unreachable while the table is total, and a hard failure rather than a
            // default so that "fail closed" never becomes "fail silently open".
            throw new LogicException(
                sprintf('The permission matrix has no cell for %s in a %s context.', $kind->value, $role->value)
            );
        }

        return $verdict;
    }

    /**
     * Whether the matrix permits the operation outright. Used by navigation
     * rendering, which needs the same answer the enforcement point gives so a
     * control is never shown for something that would be refused (Requirement 3.2).
     */
    public static function allows(OperationKind $kind, ContextRole $role): bool
    {
        return self::verdictFor($kind, $role) === Verdict::Allow;
    }

    /**
     * The whole table, for tests that assert its completeness and its shape.
     *
     * @return array<string, array<string, Verdict>>
     */
    public static function table(): array
    {
        return self::$table ??= [
            OperationKind::ViewAuthPage->value => [
                // Requirement 2.6: the two pages an unauthenticated caller must reach.
                ContextRole::Anonymous->value => Verdict::Allow,
                ContextRole::Viewer->value => Verdict::Allow,
                ContextRole::Owner->value => Verdict::Allow,
            ],
            OperationKind::ReadDiaryData->value => [
                ContextRole::Anonymous->value => Verdict::RedirectToLogin,
                // Requirement 7.2, scoped to the owner's data by resolveDataOwner.
                ContextRole::Viewer->value => Verdict::Allow,
                ContextRole::Owner->value => Verdict::Allow,
            ],
            OperationKind::WriteDiaryEntry->value => [
                ContextRole::Anonymous->value => Verdict::RedirectToLogin,
                // Requirements 5.6, 7.3.
                ContextRole::Viewer->value => Verdict::Deny,
                ContextRole::Owner->value => Verdict::Allow,
            ],
            OperationKind::WriteMilestone->value => [
                ContextRole::Anonymous->value => Verdict::RedirectToLogin,
                // Requirements 10.5, 7.3.
                ContextRole::Viewer->value => Verdict::Deny,
                ContextRole::Owner->value => Verdict::Allow,
            ],
            OperationKind::ManageAccess->value => [
                ContextRole::Anonymous->value => Verdict::RedirectToLogin,
                // Requirement 7.5: creating and revoking viewers, and deleting the
                // account, belong to the owner context alone.
                ContextRole::Viewer->value => Verdict::Deny,
                ContextRole::Owner->value => Verdict::Allow,
            ],
        ];
    }
}
