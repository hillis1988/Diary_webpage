<?php

declare(strict_types=1);

namespace Diary\Storage;

/**
 * A migration could not be loaded or applied: a malformed file name, two files
 * claiming the same version, an already-applied file whose contents changed, or
 * a statement the database refused.
 */
final class MigrationException extends StorageException
{
}
