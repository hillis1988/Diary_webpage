<?php

declare(strict_types=1);

namespace Diary\Milestone;

use Diary\Support\LocalDate;

/**
 * Validates a raw milestone submission before any write (Requirement 10.4).
 *
 * `MilestoneInput::of()` throws for a caller that has already validated its
 * arguments; this is the one place that turns untrusted, string-shaped form
 * data into either an accepted {@see MilestoneInput} or a rejection that
 * names the offending field and hands the original {@see MilestoneSubmission}
 * back unchanged, so a rejected submission can be redisplayed exactly as
 * typed and nothing is written.
 *
 * Description is checked first, then date, then category, so a submission
 * missing more than one field always names the first one a user would expect
 * to fix.
 */
final class MilestoneInputValidator
{
    public const ERROR_CODE = 'milestone_invalid';

    /** Error catalogue wording for Requirement 10.4 (blank or missing description). */
    public const DESCRIPTION_MESSAGE = 'Please provide a description for this milestone.';

    /** Error catalogue wording for Requirement 10.4 (missing date). */
    public const DATE_MESSAGE = 'Please provide a valid date for this milestone.';

    /** Error catalogue wording for Requirement 10.2 (category outside the closed set). */
    public const CATEGORY_MESSAGE = 'Please choose a valid milestone category.';

    public function validate(MilestoneSubmission $submission): MilestoneValidation
    {
        if (trim($submission->description()) === '') {
            return MilestoneValidation::rejected($submission, self::ERROR_CODE, [
                MilestoneSubmission::DESCRIPTION_FIELD => self::DESCRIPTION_MESSAGE,
            ]);
        }

        $date = LocalDate::tryFromString($submission->date());

        if ($date === null) {
            return MilestoneValidation::rejected($submission, self::ERROR_CODE, [
                MilestoneSubmission::DATE_FIELD => self::DATE_MESSAGE,
            ]);
        }

        $category = MilestoneCategory::tryFrom($submission->category());

        if ($category === null) {
            return MilestoneValidation::rejected($submission, self::ERROR_CODE, [
                MilestoneSubmission::CATEGORY_FIELD => self::CATEGORY_MESSAGE,
            ]);
        }

        $input = MilestoneInput::of($date, $submission->description(), $category);

        return MilestoneValidation::accepted($input, $submission);
    }
}
