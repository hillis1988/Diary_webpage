<?php

declare(strict_types=1);

namespace Diary\Diary;

use Diary\Access\OwnerId;
use Diary\Milestone\MilestoneRepository;
use Diary\Support\YearMonth;

/**
 * Builds a {@see CalendarMonth} for the Calendar_View (Requirements 8.1, 8.3,
 * 8.5), mirroring design.md's `Diary_Service::calendarMonth()` interface
 * method.
 *
 * This genuinely spans two domains - entry dates from
 * {@see DiaryEntryRepository}, milestone dates from {@see MilestoneRepository}
 * - so it is kept as its own small composing service rather than folded into
 * {@see DiaryService} (which otherwise knows nothing about milestones) or
 * {@see \Diary\Milestone\MilestoneService} (which otherwise knows nothing
 * about diary entries). Both repository calls are scoped to the single
 * {@see OwnerId} the caller passes in, resolved by
 * {@see \Diary\Access\AccessControlService::resolveDataOwner()} before this
 * runs, so a viewer session's calendar only ever shows indicators for the
 * Primary_User's dates (Requirement 8.5).
 */
final class CalendarService
{
    public function __construct(
        private readonly DiaryEntryRepository $diaryEntries,
        private readonly MilestoneRepository $milestones,
    ) {
    }

    public function calendarMonth(OwnerId $owner, YearMonth $month): CalendarMonth
    {
        $entryDates = $this->diaryEntries->datesWithEntries($owner, $month);

        $range = $month->toDateRange();
        $milestoneDates = [];
        foreach ($this->milestones->inRange($owner, $range) as $milestone) {
            $milestoneDates[] = $milestone->date();
        }

        return CalendarMonth::of($month, $entryDates, $milestoneDates);
    }
}
