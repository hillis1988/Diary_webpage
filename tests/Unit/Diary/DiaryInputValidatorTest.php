<?php

declare(strict_types=1);

namespace Diary\Tests\Unit\Diary;

use Diary\Diary\DiaryInputValidator;
use Diary\Diary\QuestionSet;
use Diary\Diary\SubmittedAnswers;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Requirement 5.2 fixes the mood rating scale at 1-10 and requires it on every
 * Diary_Entry; Requirement 5.5 requires that a missing or out-of-range mood
 * rating rejects the whole submission, writes nothing, and names the mood
 * rating field so the form can redisplay the problem beside it.
 */
final class DiaryInputValidatorTest extends TestCase
{
    private DiaryInputValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new DiaryInputValidator();
    }

    private function submission(array $overrides = []): SubmittedAnswers
    {
        return SubmittedAnswers::of([
            QuestionSet::DATE_FIELD => '2024-03-15',
            QuestionSet::MOOD_RATING => '7',
            QuestionSet::SLEEP_QUALITY => '3',
            QuestionSet::EVENTS => 'Went for a walk.',
            QuestionSet::THOUGHTS => 'Felt calmer than yesterday.',
            QuestionSet::EMOTIONS => 'Content',
            ...$overrides,
        ]);
    }

    private function assertRejectedNamingMoodRating(SubmittedAnswers $submitted): void
    {
        $validation = $this->validator->validate($submitted);

        self::assertTrue($validation->isRejected());
        self::assertTrue($validation->namesField(QuestionSet::MOOD_RATING));
        self::assertNotNull($validation->fieldMessage(QuestionSet::MOOD_RATING));
        self::assertSame($submitted->toArray(), $validation->answers()->toArray());

        $this->expectException(LogicException::class);
        $validation->input();
    }

    public function testMissingMoodRatingIsRejectedAndNamesTheField(): void
    {
        $submitted = $this->submission([QuestionSet::MOOD_RATING => '']);

        $this->assertRejectedNamingMoodRating($submitted);
    }

    public function testMoodRatingAbsentFromTheFormIsRejectedAndNamesTheField(): void
    {
        // Simulates a form post where the mood_rating key was never sent at all.
        $submitted = SubmittedAnswers::fromForm([
            QuestionSet::DATE_FIELD => '2024-03-15',
            QuestionSet::EVENTS => 'Went for a walk.',
        ]);

        $this->assertRejectedNamingMoodRating($submitted);
    }

    public function testMoodRatingOfZeroIsRejected(): void
    {
        $submitted = $this->submission([QuestionSet::MOOD_RATING => '0']);

        $this->assertRejectedNamingMoodRating($submitted);
    }

    public function testMoodRatingOfOneIsAccepted(): void
    {
        $validation = $this->validator->validate($this->submission([QuestionSet::MOOD_RATING => '1']));

        self::assertTrue($validation->isAccepted());
        self::assertSame(1, $validation->input()->moodRating());
    }

    public function testMoodRatingOfTenIsAccepted(): void
    {
        $validation = $this->validator->validate($this->submission([QuestionSet::MOOD_RATING => '10']));

        self::assertTrue($validation->isAccepted());
        self::assertSame(10, $validation->input()->moodRating());
    }

    public function testMoodRatingOfElevenIsRejected(): void
    {
        $submitted = $this->submission([QuestionSet::MOOD_RATING => '11']);

        $this->assertRejectedNamingMoodRating($submitted);
    }

    #[DataProvider('nonIntegerMoodRatings')]
    public function testNonIntegerMoodRatingIsRejected(string $rawValue): void
    {
        $submitted = $this->submission([QuestionSet::MOOD_RATING => $rawValue]);

        $this->assertRejectedNamingMoodRating($submitted);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function nonIntegerMoodRatings(): iterable
    {
        yield 'letters' => ['abc'];
        yield 'decimal' => ['7.5'];
        yield 'blank' => [''];
    }

    public function testWhitespacePaddedDigitsAreAcceptedBecauseSubmittedAnswersTrimsThem(): void
    {
        // SubmittedAnswers::fromForm() trims every value at submission time
        // (a form boundary concern), so " 7" and "7 " are never seen by the
        // validator as anything other than "7". They are legitimate mood
        // ratings, not non-integer input.
        $validation = $this->validator->validate($this->submission([QuestionSet::MOOD_RATING => ' 7']));
        self::assertTrue($validation->isAccepted());
        self::assertSame(7, $validation->input()->moodRating());

        $validation = $this->validator->validate($this->submission([QuestionSet::MOOD_RATING => '7 ']));
        self::assertTrue($validation->isAccepted());
        self::assertSame(7, $validation->input()->moodRating());
    }

    public function testRejectionNeverConstructsOrExposesADiaryEntryInput(): void
    {
        $validation = $this->validator->validate($this->submission([QuestionSet::MOOD_RATING => '15']));

        self::assertTrue($validation->isRejected());

        $this->expectException(LogicException::class);
        $validation->input();
    }

    public function testAValidSubmissionIsAcceptedWithMatchingValues(): void
    {
        $submitted = $this->submission();

        $validation = $this->validator->validate($submitted);

        self::assertTrue($validation->isAccepted());

        $input = $validation->input();
        self::assertSame('2024-03-15', $input->date()->toIso());
        self::assertSame(7, $input->moodRating());
        self::assertSame(3, $input->sleepQuality());
        self::assertSame('Went for a walk.', $input->events());
        self::assertSame('Felt calmer than yesterday.', $input->thoughts());
        self::assertSame('Content', $input->emotions());
        self::assertSame($submitted->toArray(), $validation->answers()->toArray());
    }

    public function testBlankSleepQualityIsAcceptedAsOptional(): void
    {
        $validation = $this->validator->validate($this->submission([QuestionSet::SLEEP_QUALITY => '']));

        self::assertTrue($validation->isAccepted());
        self::assertNull($validation->input()->sleepQuality());
    }
}
