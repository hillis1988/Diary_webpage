<?php

declare(strict_types=1);

namespace Diary\Auth;

use DateTimeImmutable;

/**
 * One row of the `sessions` table, in memory.
 *
 * `contextRole` and `dataOwnerId` are copied from the account at sign-in and
 * never recomputed afterwards (Requirement 7.3). That freeze is the single point
 * where "a viewer context can never write" is decided, so nothing here offers a
 * way to change either value on an existing session.
 *
 * The raw token is not part of the row. A session created by
 * {@see Session::start()} carries it in memory so the caller can put it in the
 * cookie, and it is null on every session read back from the database.
 */
final class Session
{
    public function __construct(
        public readonly SessionId $id,
        public readonly UserId $userId,
        public readonly UserRole $contextRole,
        public readonly UserId $dataOwnerId,
        public readonly DateTimeImmutable $createdAt,
        public readonly DateTimeImmutable $lastActivityAt,
        public readonly ?DateTimeImmutable $terminatedAt = null,
        private readonly ?SessionToken $issuedToken = null,
    ) {
    }

    /**
     * A fresh session for an account that has just proved its password.
     */
    public static function start(UserAccount $account, SessionToken $token, DateTimeImmutable $now): self
    {
        return new self(
            id: $token->id(),
            userId: $account->id,
            contextRole: $account->role,
            dataOwnerId: $account->dataOwnerId,
            createdAt: $now,
            lastActivityAt: $now,
            terminatedAt: null,
            issuedToken: $token,
        );
    }

    /**
     * The cookie value for a session that was just created, or null for one read
     * from the database. It is never stored and never logged.
     */
    public function issuedToken(): ?SessionToken
    {
        return $this->issuedToken;
    }

    public function isTerminated(): bool
    {
        return $this->terminatedAt !== null;
    }
}
