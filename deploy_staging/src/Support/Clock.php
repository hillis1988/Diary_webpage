<?php

declare(strict_types=1);

namespace Diary\Support;

use DateTimeImmutable;

/**
 * Source of the current time.
 *
 * Every component that cares about elapsed time (lockout windows, session
 * idleness, timestamps) takes a Clock rather than calling time() directly, so
 * tests can generate arbitrary elapsed intervals without sleeping.
 *
 * Implementations MUST return times in UTC.
 */
interface Clock
{
    public function now(): DateTimeImmutable;
}
