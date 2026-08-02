<?php

declare(strict_types=1);

namespace Diary\Support;

use DateInterval;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;

/**
 * A Clock frozen at an instant and moved forward only when asked.
 *
 * Used by tests to drive lockout windows, session idleness and date boundaries
 * deterministically. It is deliberately mutable: advancing time is the point.
 */
final class FixedClock implements Clock
{
    private DateTimeImmutable $now;

    public function __construct(DateTimeInterface $now)
    {
        $this->now = self::toUtc($now);
    }

    /**
     * @param string $expression any expression DateTimeImmutable understands,
     *                           interpreted as UTC when no zone is given
     */
    public static function at(string $expression): self
    {
        try {
            $parsed = new DateTimeImmutable($expression, new DateTimeZone('UTC'));
        } catch (\Exception $e) {
            throw new InvalidArgumentException(
                sprintf('Not a valid date/time expression: "%s"', $expression),
                0,
                $e
            );
        }

        return new self($parsed);
    }

    public function now(): DateTimeImmutable
    {
        return $this->now;
    }

    public function set(DateTimeInterface $now): void
    {
        $this->now = self::toUtc($now);
    }

    /**
     * Move the clock forward (or backward with a negative value) by seconds.
     */
    public function advanceSeconds(int $seconds): void
    {
        $this->now = $this->now->modify(sprintf('%+d seconds', $seconds));
    }

    public function advanceMinutes(int $minutes): void
    {
        $this->advanceSeconds($minutes * 60);
    }

    public function advanceDays(int $days): void
    {
        $this->now = $this->now->modify(sprintf('%+d days', $days));
    }

    public function advance(DateInterval $interval): void
    {
        $this->now = $this->now->add($interval);
    }

    private static function toUtc(DateTimeInterface $moment): DateTimeImmutable
    {
        $immutable = $moment instanceof DateTimeImmutable
            ? $moment
            : DateTimeImmutable::createFromInterface($moment);

        return $immutable->setTimezone(new DateTimeZone('UTC'));
    }
}
