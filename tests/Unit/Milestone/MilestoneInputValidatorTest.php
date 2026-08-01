<?php

declare(strict_types=1);

namespace Diary\Tests\Unit\Milestone;

use Diary\Milestone\MilestoneInputValidator;
use Diary\Milestone\MilestoneSubmission;
use LogicException;
use PHPUnit\Framework\TestCase;

/**
 * Requirement 10.4 requires a blank or missing description, a missing date,
 * or a category outside the closed set to reject the whole submission, write
 * nothing, and name the offending field. Requirement 10.2 fixes the category
 * to the closed set medication, relationship, lifestyle, other.
 *
 * A focused smoke test of the implementation built in task 12.1; exhaustive
 * invalid-input coverage is task 12.3's job.
 */
final class MilestoneInputValidatorTest extends TestCase
{
    private MilestoneInputValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new MilestoneInputValidator();
    }

    private function submission(array $overrides = []): MilestoneSubmission
    {
        return MilestoneSubmission::of([
            MilestoneSubmission::DATE_FIELD => '2025-03-01',
            MilestoneSubmission::DESCRIPTION_FIELD => 'Started a new medication',
            MilestoneSubmission::CATEGORY_FIELD => 'medication',
            ...$overrides,
        ]);
    }

    public function testAValidSubmissionIsAccepted(): void
    {
        $validation = $this->validator->validate($this->submission());

        self::assertTrue($validation->isAccepted());
        self::assertSame('Started a new medication', $validation->input()->description());
        self::assertSame('2025-03-01', $validation->input()->date()->toIso());
    }

    public function testBlankDescriptionIsRejectedAndNamesTheField(): void
    {
        $submitted = $this->submission([MilestoneSubmission::DESCRIPTION_FIELD => '   ']);

        $validation = $this->validator->validate($submitted);

        self::assertTrue($validation->isRejected());
        self::assertTrue($validation->namesField(MilestoneSubmission::DESCRIPTION_FIELD));

        $this->expectException(LogicException::class);
        $validation->input();
    }

    public function testMissingDescriptionIsRejectedAndNamesTheField(): void
    {
        $submitted = MilestoneSubmission::fromForm([
            MilestoneSubmission::DATE_FIELD => '2025-03-01',
            MilestoneSubmission::CATEGORY_FIELD => 'medication',
        ]);

        $validation = $this->validator->validate($submitted);

        self::assertTrue($validation->isRejected());
        self::assertTrue($validation->namesField(MilestoneSubmission::DESCRIPTION_FIELD));
    }

    public function testMissingDateIsRejectedAndNamesTheField(): void
    {
        $submitted = $this->submission([MilestoneSubmission::DATE_FIELD => '']);

        $validation = $this->validator->validate($submitted);

        self::assertTrue($validation->isRejected());
        self::assertTrue($validation->namesField(MilestoneSubmission::DATE_FIELD));
        self::assertSame($submitted->toArray(), $validation->submission()->toArray());
    }

    public function testCategoryOutsideTheClosedSetIsRejectedAndNamesTheField(): void
    {
        $submitted = $this->submission([MilestoneSubmission::CATEGORY_FIELD => 'career']);

        $validation = $this->validator->validate($submitted);

        self::assertTrue($validation->isRejected());
        self::assertTrue($validation->namesField(MilestoneSubmission::CATEGORY_FIELD));
    }

    public function testEachCategoryInTheClosedSetIsAccepted(): void
    {
        foreach (['medication', 'relationship', 'lifestyle', 'other'] as $category) {
            $validation = $this->validator->validate(
                $this->submission([MilestoneSubmission::CATEGORY_FIELD => $category])
            );

            self::assertTrue($validation->isAccepted(), "Category \"$category\" should be accepted.");
            self::assertSame($category, $validation->input()->category()->value);
        }
    }

    public function testRejectionPreservesSubmittedValuesForRedisplay(): void
    {
        $submitted = $this->submission([
            MilestoneSubmission::DESCRIPTION_FIELD => '',
            MilestoneSubmission::CATEGORY_FIELD => 'lifestyle',
        ]);

        $validation = $this->validator->validate($submitted);

        self::assertTrue($validation->isRejected());
        self::assertSame($submitted->toArray(), $validation->submission()->toArray());
    }
}
