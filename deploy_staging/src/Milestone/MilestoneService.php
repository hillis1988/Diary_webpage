<?php

declare(strict_types=1);

namespace Diary\Milestone;

use Diary\Access\OwnerId;
use Diary\Support\Clock;
use Diary\Support\DateRange;
use Diary\Support\Result;

/**
 * Milestone_Service (Requirements 10.1, 10.2, 10.3, 10.4, 10.5).
 *
 * Kept thin on purpose, following the established shape of
 * {@see \Diary\Diary\DiaryService}: this class only proceeds on an accepted
 * {@see MilestoneValidation} and delegates the encrypted storage operations to
 * {@see MilestoneRepository}. It takes an {@see OwnerId} directly rather than
 * a `SecurityContext` - the milestone pages and controller (a later task) own
 * turning a raw HTTP request into a resolved owner scope via
 * `AccessControlService::resolveDataOwner()` and an `authorise()` check
 * before calling this, the same division DiaryService already established
 * for diary entries. That keeps this service free of anything HTTP-shaped and
 * restricting mutation to an owner context is enforced by the caller, exactly
 * as it is for diary entries.
 */
final class MilestoneService
{
    public const NOT_FOUND_ERROR_CODE = 'milestone_not_found';
    public const NOT_FOUND_MESSAGE = 'That milestone could not be found.';

    public function __construct(private readonly MilestoneRepository $repository)
    {
    }

    /**
     * Only proceeds on an accepted validation (Requirement 10.4: a rejected
     * submission performs no write at all).
     *
     * @return Result<Milestone>
     */
    public function create(OwnerId $owner, MilestoneValidation $validation, Clock $clock): Result
    {
        if ($validation->isRejected()) {
            return $validation->result();
        }

        $milestone = $this->repository->create($owner, $validation->input(), $clock);

        return Result::ok($milestone);
    }

    /**
     * Applies the requested change to the stored milestone (Requirement
     * 10.3). Only proceeds on an accepted validation, and only touches a
     * milestone belonging to this owner.
     *
     * @return Result<Milestone>
     */
    public function update(OwnerId $owner, MilestoneId $id, MilestoneValidation $validation, Clock $clock): Result
    {
        if ($validation->isRejected()) {
            return $validation->result();
        }

        $milestone = $this->repository->update($owner, $id, $validation->input(), $clock);

        if ($milestone === null) {
            return self::notFound();
        }

        return Result::ok($milestone);
    }

    /**
     * @return Result<null>
     */
    public function delete(OwnerId $owner, MilestoneId $id): Result
    {
        $deleted = $this->repository->delete($owner, $id);

        if (!$deleted) {
            return self::notFound();
        }

        return Result::ok(null);
    }

    /**
     * Every milestone belonging to the owner whose date falls within the
     * given range (Requirements 8.3, 9.3).
     *
     * @return list<Milestone>
     */
    public function inRange(OwnerId $owner, DateRange $range): array
    {
        return $this->repository->inRange($owner, $range);
    }

    /**
     * A thin passthrough to {@see MilestoneRepository::findById()}, mirroring
     * {@see \Diary\Diary\DiaryService::entryForDate()}. Exists so the
     * milestone edit form re-fetches a single milestone through
     * Milestone_Service rather than reaching past it for the repository.
     */
    public function find(OwnerId $owner, MilestoneId $id): ?Milestone
    {
        return $this->repository->findById($owner, $id);
    }

    /**
     * @return Result<null>
     */
    private static function notFound(): Result
    {
        return Result::failure(self::NOT_FOUND_ERROR_CODE, self::NOT_FOUND_MESSAGE);
    }
}
