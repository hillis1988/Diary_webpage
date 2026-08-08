<?php

declare(strict_types=1);

namespace Diary\Ai;

use Diary\Support\DateRange;
use JsonSerializable;

/**
 * Closed user-message JSON for the dietitian summary call.
 */
final class DietSummaryPayload implements JsonSerializable
{
    /**
     * @param list<DietEntryContent> $entries
     */
    public function __construct(
        private readonly DateRange $range,
        private readonly array $entries,
    ) {
    }

    /**
     * @return array{
     *     date_range: array{start: string, end: string},
     *     entries: list<DietEntryContent>
     * }
     */
    public function jsonSerialize(): array
    {
        return [
            'date_range' => [
                'start' => $this->range->start()->toIso(),
                'end' => $this->range->end()->toIso(),
            ],
            'entries' => $this->entries,
        ];
    }
}
