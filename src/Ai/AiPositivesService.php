<?php

declare(strict_types=1);

namespace Diary\Ai;

use Diary\Access\OwnerId;
use Diary\Diary\DiaryEntry;
use Diary\Diary\DiaryService;
use Diary\Milestone\Milestone;
use Diary\Milestone\MilestoneService;
use Diary\Support\DateRange;

/**
 * Bright Spots service: pulls past diary/milestone content in a date range and
 * asks the AI for optimistic, friend-toned reminders of what went well.
 *
 * If the AI provider fails, a deterministic fallback is built from the highest
 * mood entries so the page still has something useful to show.
 */
final class AiPositivesService
{
    /** At least this many entries are needed before calling the provider. */
    private const MINIMUM_ENTRIES = 1;

    public function __construct(
        private readonly DiaryService $diaryService,
        private readonly MilestoneService $milestoneService,
        private readonly PositivesProvider $provider,
        private readonly TrendCalculator $trendCalculator = new TrendCalculator(),
    ) {
    }

    public function remind(OwnerId $owner, DateRange $range): PositivesOutcome
    {
        $entries = $this->diaryService->entriesInRange($owner, $range);

        if (count($entries) < self::MINIMUM_ENTRIES) {
            return PositivesOutcome::insufficientData();
        }

        $milestones = $this->milestoneService->inRange($owner, $range);
        $metrics = $this->trendCalculator->compute($entries);
        $input = new SummaryInput(
            $range,
            $metrics,
            array_map(static fn (DiaryEntry $entry): SummaryEntryContent => SummaryEntryContent::fromEntry($entry), $entries),
            array_map(static fn (Milestone $milestone): SummaryMilestoneContent => SummaryMilestoneContent::fromMilestone($milestone), $milestones),
        );

        try {
            $reminder = $this->provider->generate($input);
            if ($reminder->highlights() !== []) {
                return PositivesOutcome::reminder($reminder);
            }
        } catch (ProviderError) {
            // Fall through to the local reminder built from diary content.
        }

        return PositivesOutcome::reminder(self::fallbackReminder($entries, $milestones));
    }

    /**
     * @param list<DiaryEntry> $entries
     * @param list<Milestone> $milestones
     */
    private static function fallbackReminder(array $entries, array $milestones): PositivesReminder
    {
        $sorted = $entries;
        usort(
            $sorted,
            static function (DiaryEntry $a, DiaryEntry $b): int {
                $mood = $b->input()->moodRating() <=> $a->input()->moodRating();
                return $mood !== 0 ? $mood : ($b->date()->toEpochDay() <=> $a->date()->toEpochDay());
            }
        );

        $highlights = [];
        foreach (array_slice($sorted, 0, 4) as $entry) {
            $events = trim($entry->input()->events());
            $title = $events !== ''
                ? self::clip($events, 72)
                : 'A steadier day — mood ' . $entry->input()->moodRating() . '/10';

            $why = $events !== ''
                ? 'On ' . $entry->date()->toIso() . ' you wrote about something real that happened: '
                    . self::clip($events, 160)
                    . ' Holding onto days like that is useful — they are evidence you keep showing up.'
                : 'On ' . $entry->date()->toIso() . ' your mood sat at '
                    . $entry->input()->moodRating()
                    . '/10. Days that feel even a little better are worth remembering.';

            $highlights[] = new PositiveHighlight(
                $entry->date()->toIso(),
                $title,
                $why,
                'Look for one small repeat of what helped that day — even a shorter version counts.',
            );
        }

        foreach (array_slice($milestones, 0, 2) as $milestone) {
            $highlights[] = new PositiveHighlight(
                $milestone->date()->toIso(),
                self::clip($milestone->description(), 72),
                'You marked this as a milestone on ' . $milestone->date()->toIso()
                    . '. That means it mattered enough to record — treat that as proof of progress.',
                'When the week feels flat, reopen this milestone and remember you already moved something forward.',
            );
        }

        return new PositivesReminder(
            'Hey — even without the AI write-up, your own diary already has bright spots in it.',
            'Here are a few moments pulled straight from what you logged. They are yours. Keep stacking days like these.',
            array_slice($highlights, 0, 5),
        );
    }

    private static function clip(string $text, int $max): string
    {
        $text = trim(preg_replace('/\s+/', ' ', $text) ?? $text);
        if (strlen($text) <= $max) {
            return $text;
        }

        return rtrim(substr($text, 0, $max - 1)) . '…';
    }
}
