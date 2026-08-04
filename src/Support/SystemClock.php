<?php

declare(strict_types=1);

namespace Diary\Support;

use DateTimeImmutable;
use DateTimeZone;

/**
 * The production Clock: real wall-clock time, always UTC.
 */
final class SystemClock implements Clock
{
    private readonly DateTimeZone $utc;

    public function __construct()
    {
        $this->utc = new DateTimeZone('UTC');
    }

    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', $this->utc);
    }
}
