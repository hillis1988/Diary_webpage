<?php

declare(strict_types=1);

namespace Diary\Auth;

use InvalidArgumentException;

/**
 * Turns a caller address into the keyed hash stored in `audit_log.ip_hash`.
 *
 * The raw address is never stored. A keyed hash rather than a plain one, because
 * the space of IPv4 addresses is small enough to enumerate: without a secret key,
 * a stored digest is as good as the address itself.
 *
 * The hash is stable for a given address and key, so repeated attempts from one
 * source can still be correlated - which is the reason the column exists.
 */
final class IpHasher
{
    public function __construct(private readonly string $key)
    {
        if ($key === '') {
            throw new InvalidArgumentException('An ip hash key is required; an unkeyed digest is reversible.');
        }
    }

    /**
     * @param string|null $ipAddress the caller address, or null when there is none
     *                               (a CLI run, or a proxy that sent nothing usable)
     *
     * @return string|null 64 lowercase hex characters, or null when there is no address
     */
    public function hash(?string $ipAddress): ?string
    {
        $address = strtolower(trim((string) $ipAddress));

        if ($address === '') {
            return null;
        }

        return hash_hmac('sha256', $address, $this->key);
    }
}
