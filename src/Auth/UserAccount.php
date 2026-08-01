<?php

declare(strict_types=1);

namespace Diary\Auth;

use DateTimeImmutable;

/**
 * One row of the `users` table, in memory.
 *
 * Immutable on purpose: nothing outside the repository changes an account in
 * place, so a service cannot accidentally leave a half-updated account behind.
 * State changes (lockout counters, status, deletion request) are repository
 * operations that return a fresh instance.
 *
 * The password hash lives here because authentication needs it, but it is never
 * rendered and never included in any log or AI prompt.
 */
final class UserAccount
{
    public function __construct(
        public readonly UserId $id,
        public readonly string $emailNormalized,
        public readonly string $emailDisplay,
        public readonly ?string $passwordHash,
        public readonly UserRole $role,
        public readonly UserId $dataOwnerId,
        public readonly UserStatus $status,
        public readonly int $failedLoginCount,
        public readonly ?DateTimeImmutable $lockedUntil,
        public readonly ?DateTimeImmutable $deletionRequestedAt,
        public readonly DateTimeImmutable $createdAt,
        public readonly DateTimeImmutable $updatedAt,
    ) {
    }

    /**
     * A freshly registered Primary_User (Requirement 1.1): Owner_Role, active
     * straight away because they chose their own password, and its own data owner
     * so every owner-scoped query for this account resolves to itself.
     */
    public static function newOwner(
        UserId $id,
        EmailAddress $email,
        string $passwordHash,
        DateTimeImmutable $now,
    ): self {
        return new self(
            id: $id,
            emailNormalized: $email->normalized(),
            emailDisplay: $email->display(),
            passwordHash: $passwordHash,
            role: UserRole::Owner,
            dataOwnerId: $id,
            status: UserStatus::Active,
            failedLoginCount: 0,
            lockedUntil: null,
            deletionRequestedAt: null,
            createdAt: $now,
            updatedAt: $now,
        );
    }

    public function isOwner(): bool
    {
        return $this->role->isOwner();
    }

    public function ownsItsOwnData(): bool
    {
        return $this->dataOwnerId->equals($this->id);
    }
}
