<?php

declare(strict_types=1);

namespace Diary\Auth;

use DateTimeImmutable;
use Diary\Support\Ulid;
use InvalidArgumentException;

/**
 * One security event, on its way to `audit_log`.
 *
 * The whole point of this object is that it cannot carry health data. Every field
 * is either a closed enum, an identifier of a fixed shape, or a hash, and the
 * constructor rejects anything else: there is no free-text field to put a diary
 * answer, a milestone description, an email address or a password into.
 *
 * `targetType` is the only string, and it names a kind of record ('session',
 * 'diary_entry'), never its contents.
 */
final class AuditLogEntry
{
    /** `audit_log.target_type` is VARCHAR(64). */
    public const MAX_TARGET_TYPE_LENGTH = 64;

    /** `audit_log.ip_hash` is CHAR(64): a keyed SHA-256, never a raw address. */
    public const IP_HASH_LENGTH = 64;

    public function __construct(
        public readonly string $id,
        public readonly ?UserId $actorUserId,
        public readonly AuditContextRole $contextRole,
        public readonly AuditAction $action,
        public readonly AuditOutcome $outcome,
        public readonly DateTimeImmutable $occurredAt,
        public readonly ?string $targetType = null,
        public readonly ?UserId $targetId = null,
        public readonly ?string $ipHash = null,
    ) {
        if (!Ulid::isValid($id)) {
            throw new InvalidArgumentException('An audit log id must be a 26-character ULID.');
        }

        if ($targetType !== null
            && ($targetType === '' || strlen($targetType) > self::MAX_TARGET_TYPE_LENGTH)
        ) {
            throw new InvalidArgumentException('An audit target type must be 1 to 64 characters.');
        }

        if ($ipHash !== null && preg_match('/^[0-9a-f]{' . self::IP_HASH_LENGTH . '}$/', $ipHash) !== 1) {
            // A raw address would fail this, which is the point: the column only
            // ever holds the keyed hash produced by {@see IpHasher}.
            throw new InvalidArgumentException('An audit ip hash must be 64 lowercase hex characters.');
        }
    }
}
