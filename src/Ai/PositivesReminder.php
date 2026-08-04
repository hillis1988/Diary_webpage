<?php

declare(strict_types=1);

namespace Diary\Ai;

/**
 * The friend-toned reminder payload returned by {@see PositivesProvider}.
 *
 * @param list<PositiveHighlight> $highlights
 */
final class PositivesReminder
{
    /**
     * @param list<PositiveHighlight> $highlights
     */
    public function __construct(
        private readonly string $greeting,
        private readonly string $encouragement,
        private readonly array $highlights,
    ) {
    }

    public function greeting(): string
    {
        return $this->greeting;
    }

    public function encouragement(): string
    {
        return $this->encouragement;
    }

    /**
     * @return list<PositiveHighlight>
     */
    public function highlights(): array
    {
        return $this->highlights;
    }
}
