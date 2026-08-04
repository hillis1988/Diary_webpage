<?php

declare(strict_types=1);

namespace Diary\Support;

/**
 * The rows of the permission matrix.
 *
 * Authorisation decisions are keyed on operation kind and context role only, so
 * this closed set is the complete vocabulary the matrix has to cover.
 */
enum OperationKind: string
{
    /** Login and registration pages: reachable while anonymous. */
    case ViewAuthPage = 'view_auth_page';

    /** Reading entries, recommendations, milestones, the calendar or a summary. */
    case ReadDiaryData = 'read_diary_data';

    /** Creating, updating or deleting a diary entry. */
    case WriteDiaryEntry = 'write_diary_entry';

    /** Creating, updating or deleting a milestone. */
    case WriteMilestone = 'write_milestone';

    /** Creating or revoking a viewer, and deleting the account. */
    case ManageAccess = 'manage_access';

    /**
     * Whether the operation can change stored state. Every mutating kind is
     * denied in a viewer context (Requirements 3.3, 5.6, 7.3, 7.5, 10.5).
     */
    public function isMutating(): bool
    {
        return match ($this) {
            self::ViewAuthPage, self::ReadDiaryData => false,
            self::WriteDiaryEntry, self::WriteMilestone, self::ManageAccess => true,
        };
    }
}
