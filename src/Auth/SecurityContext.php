<?php

declare(strict_types=1);

namespace Diary\Auth;

use LogicException;

/**
 * Who a request is acting as, and whose data it may touch.
 *
 * The design makes this the single input to authorisation:
 *
 *     SecurityContext { userId, contextRole, dataOwnerId }
 *
 * Every field is copied from the session row, never recomputed from `users`
 * mid-request (Requirement 7.3). There is no setter and no subclass: a context is
 * built once, by {@see forSession()} for a session that resolved or by
 * {@see anonymous()} for one that did not, and passed down unchanged.
 *
 * The two constructors are the whole of the "fail closed" rule: anything that
 * cannot produce a live session produces {@see anonymous()}, which carries no
 * user id and no owner scope, so a handler cannot accidentally read somebody's
 * diary with it.
 */
final class SecurityContext
{
    private function __construct(
        public readonly ?UserId $userId,
        public readonly ContextRole $contextRole,
        public readonly ?UserId $dataOwnerId,
    ) {
        // An anonymous context with a user id, or a signed-in one without, would
        // let a caller read one field and act on the other. Neither can be built.
        if ($contextRole->isAnonymous() && ($userId !== null || $dataOwnerId !== null)) {
            throw new LogicException('An anonymous security context carries no user id and no data owner.');
        }

        if (!$contextRole->isAnonymous() && ($userId === null || $dataOwnerId === null)) {
            throw new LogicException('A signed-in security context needs both a user id and a data owner.');
        }
    }

    /**
     * No cookie, no session, a signed-out session, or one idle past the timeout.
     */
    public static function anonymous(): self
    {
        return new self(null, ContextRole::Anonymous, null);
    }

    /**
     * The context of a session that has just resolved. The role and the owner scope
     * are the session's frozen copies, not the account's current values.
     */
    public static function forSession(Session $session): self
    {
        return new self(
            $session->userId,
            ContextRole::fromUserRole($session->contextRole),
            $session->dataOwnerId,
        );
    }

    public function isAnonymous(): bool
    {
        return $this->contextRole->isAnonymous();
    }

    public function isAuthenticated(): bool
    {
        return !$this->isAnonymous();
    }

    /**
     * True only for an owner context. A viewer context and an anonymous one are
     * both read-only, which is what makes Requirement 7.3 one check rather than two.
     */
    public function isOwner(): bool
    {
        return $this->contextRole->isOwner();
    }
}
