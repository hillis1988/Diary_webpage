<?php

declare(strict_types=1);

namespace Diary\Ai;

use Diary\Support\DateRange;

/**
 * Input to the dietitian summary provider: the selected range and the days
 * that carry food diary rows (each still including same-day mood context).
 */
final class DietSummaryInput
{
    /**
     * @param list<DietEntryContent> $entries
     */
    public function __construct(
        private readonly DateRange $range,
        private readonly array $entries,
    ) {
    }

    public function range(): DateRange
    {
        return $this->range;
    }

    /**
     * @return list<DietEntryContent>
     */
    public function entries(): array
    {
        return $this->entries;
    }
}
