<?php

declare(strict_types=1);

namespace Diary\Ai;

use Diary\Access\OwnerId;
use Diary\Diary\DiaryEntry;
use Diary\Diary\DiaryService;
use Diary\Support\DateRange;

/**
 * Optional second AI pass on the progress summary page: dietitian notes when
 * the selected range includes at least one food diary meal.
 */
final class AiDietSummaryService
{
    public function __construct(
        private readonly DiaryService $diaryService,
        private readonly DietSummaryProvider $provider,
    ) {
    }

    public function analyse(OwnerId $owner, DateRange $range): DietSummaryOutcome
    {
        $entries = $this->diaryService->entriesInRange($owner, $range);
        $withFood = [];

        foreach ($entries as $entry) {
            if (!$entry->input()->foodDiary()->isEmpty()) {
                $withFood[] = DietEntryContent::fromEntry($entry);
            }
        }

        if ($withFood === []) {
            return DietSummaryOutcome::skipped();
        }

        $input = new DietSummaryInput($range, $withFood);

        try {
            return DietSummaryOutcome::notes($this->provider->generate($input));
        } catch (ProviderError) {
            return DietSummaryOutcome::unavailable();
        }
    }
}
