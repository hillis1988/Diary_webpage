<?php

declare(strict_types=1);

namespace Diary\Storage;

use RuntimeException;

/**
 * An unrecoverable storage-layer fault: bad database configuration, a refused
 * connection, or a migration that cannot be applied.
 *
 * These are not expected user-facing failures, so they are exceptions rather
 * than a Result. Nothing in this hierarchy ever carries the database password
 * in its message.
 */
class StorageException extends RuntimeException
{
}
