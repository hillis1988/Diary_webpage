<?php

declare(strict_types=1);

namespace Diary\Diary;

use Diary\Support\LocalDate;
use Diary\Support\YearMonth;

/**
 * `CalendarMonth { month, entryDates: Set<LocalDate>, milestoneDates: Set<LocalDate> }`
 * from design.md, built for the Calendar_View (Requirements 8.1, 8.3, 8.5).
 *
 * Both date collections are already scoped to one owner and one month by the
 * time this object exists - {@see CalendarService::calendarMonth()} is the
 * only place that builds one - so nothing here re-checks ownership; this is
 * purely the shape the view renders from. "Set" is read as "no date appears
 * twice and lookup does not care about order", which {@see of()} enforces by
 * de-duplicating on the ISO string; both accessors return the dates ascending
 * for a stable rendering.
 */
final class CalendarMonth
{
    /** @var array<string, LocalDate> keyed by ISO date, so lookups and de-duplication are cheap */
    private readonly array $entryDates;

    /** @var array<string, LocalDate> keyed by ISO date */
    private readonly array $milestoneDates;

    private function __construct(
        private readonly YearMonth $month,
        array $entryDates,
        array $milestoneDates,
    ) {
        $this->entryDates = self::index($entryDates);
        $this->milestoneDates = self::index($milestoneDates);
    }

    /**
     * @param list<LocalDate> $entryDates
     * @param list<LocalDate> $milestoneDates
     */
    public static function of(YearMonth $month, array $entryDates, array $milestoneDates): self
    {
        return new self($month, $entryDates, $milestoneDates);
    }

    public function month(): YearMonth
    {
        return $this->month;
    }

    /**
     * @return list<LocalDate> ascending, each date appearing once
     */
    public function entryDates(): array
    {
        return array_values($this->entryDates);
    }

    /**
     * @return list<LocalDate> ascending, each date appearing once
     */
    public function milestoneDates(): array
    {
        return array_values($this->milestoneDates);
    }

    /** Requirement 8.1: whether this date carries a Diary_Entry indicator. */
    public function hasEntryOn(LocalDate $date): bool
    {
        return isset($this->entryDates[$date->toIso()]);
    }

    /** Requirement 8.3: whether this date carries a Milestone indicator. */
    public function hasMilestoneOn(LocalDate $date): bool
    {
        return isset($this->milestoneDates[$date->toIso()]);
    }

    /**
     * @param list<LocalDate> $dates
     * @return array<string, LocalDate>
     */
    private static function index(array $dates): array
    {
        $indexed = [];
        foreach ($dates as $date) {
            $indexed[$date->toIso()] = $date;
        }

        ksort($indexed);

        return $indexed;
    }
}
