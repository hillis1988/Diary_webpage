<?php

declare(strict_types=1);

namespace Diary\Storage;

/**
 * What one `KeyRotationService::runSlice` call did: how many rows were moved
 * onto the current active key, how many of those attempts failed and were
 * left on their old key for a later run, and how many now-unreferenced keys
 * were retired (Requirement 4.2).
 */
final class KeyRotationReport
{
    private function __construct(
        public readonly int $reEncrypted,
        public readonly int $failed,
        public readonly int $retiredKeys,
    ) {
    }

    public static function of(int $reEncrypted, int $failed, int $retiredKeys): self
    {
        return new self($reEncrypted, $failed, $retiredKeys);
    }

    public static function empty(): self
    {
        return new self(0, 0, 0);
    }
}
