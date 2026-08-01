<?php

declare(strict_types=1);

namespace Diary\Diary;

use Diary\Access\OwnerId;
use Diary\Support\Clock;
use Diary\Support\LocalDate;
use Diary\Support\Result;

/**
 * Diary_Service's submission half (Requirements 5.2-5.6).
 *
 * Kept thin on purpose: this class only proceeds on an accepted
 * {@see DiaryValidation} and delegates the encrypted upsert to
 * {@see DiaryEntryRepository}. The entry page and controller (a later task)
 * own turning a raw HTTP submission into a {@see DiaryValidation} via
 * {@see DiaryInputValidator} before calling this; that keeps validation
 * testable on its own and this service free of anything HTTP-shaped.
 */
final class DiaryService
{
    public function __construct(private readonly DiaryEntryRepository $repository)
    {
    }

    /**
     * The structured question set the entry form, validator and AI prompt all
     * read from a single definition (Requirement 5.1).
     *
     * @return list<QuestionDefinition>
     */
    public function questionSet(): array
    {
        return QuestionSet::definitions();
    }

    /**
     * Only proceeds on an accepted validation (Requirement 5.5: a rejected
     * submission performs no write at all). On acceptance, upserts the entry
     * keyed on `(owner_id, entry_date)` so a second submission for a date
     * updates rather than duplicates (Requirement 5.4).
     *
     * @return Result<DiaryEntry>
     */
    public function submitEntry(OwnerId $owner, DiaryValidation $validation, Clock $clock): Result
    {
        if ($validation->isRejected()) {
            return $validation->result();
        }

        $entry = $this->repository->upsert($owner, $validation->input(), $clock);

        return Result::ok($entry);
    }

    /**
     * A thin passthrough to {@see DiaryEntryRepository::findByDate()},
     * mirroring design.md's `entryForDate` interface method. Exists so a
     * caller re-fetching an owner's entry - the feedback retry route, a later
     * task's entry page - goes through Diary_Service rather than reaching
     * past it for the repository (Requirement 8.2).
     */
    public function entryForDate(OwnerId $owner, LocalDate $date): ?DiaryEntry
    {
        return $this->repository->findByDate($owner, $date);
    }
}
