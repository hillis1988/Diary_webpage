<?php

declare(strict_types=1);

namespace Diary\Diary;

use Diary\Support\LocalDate;

/**
 * Validates a raw diary submission before any write (Requirement 5.2, 5.5).
 *
 * `DiaryEntryInput::of()` throws for a caller that has already validated its
 * arguments; this is the one place that turns untrusted, string-shaped form
 * data into either an accepted {@see DiaryEntryInput} or a rejection that
 * names the offending field and hands the original {@see SubmittedAnswers}
 * back unchanged, so a rejected submission can be redisplayed exactly as
 * typed and nothing is written.
 *
 * Mood rating is checked first because it is the only required question
 * (Requirement 5.2): a missing, non-integer, or out-of-range mood rating is
 * rejected before anything else is even looked at.
 */
final class DiaryInputValidator
{
    public const ERROR_CODE = 'diary_entry_invalid';

    /** Error catalogue wording for Requirements 5.2, 5.5 (missing or out of range). */
    public const MOOD_RATING_MESSAGE = 'Please give a mood rating from 1 to 10';

    public function validate(SubmittedAnswers $answers): DiaryValidation
    {
        $moodRating = $this->parseScaleAnswer(
            $answers,
            QuestionSet::moodRating(),
            required: true,
            invalidMessage: self::MOOD_RATING_MESSAGE,
        );

        if ($moodRating instanceof DiaryValidation) {
            return $moodRating;
        }

        $sleepQuality = $this->parseScaleAnswer(
            $answers,
            QuestionSet::sleepQuality(),
            required: false,
        );

        if ($sleepQuality instanceof DiaryValidation) {
            return $sleepQuality;
        }

        $date = LocalDate::tryFromString($answers->date());

        if ($date === null) {
            return DiaryValidation::rejected($answers, self::ERROR_CODE, [
                QuestionSet::DATE_FIELD => 'Please provide a valid date for this entry.',
            ]);
        }

        $input = DiaryEntryInput::of(
            date: $date,
            moodRating: $moodRating,
            sleepQuality: $sleepQuality,
            events: $answers->value(QuestionSet::EVENTS),
            thoughts: $answers->value(QuestionSet::THOUGHTS),
            emotions: $answers->value(QuestionSet::EMOTIONS),
        );

        return DiaryValidation::accepted($input, $answers);
    }

    /**
     * Parses one scale-type answer (mood rating or sleep quality) out of the
     * submitted string.
     *
     * Returns the parsed integer on success, `null` when the answer is
     * optional and was left blank, or a rejected {@see DiaryValidation} that
     * names the field for every other case: missing when required, not a
     * whole number, or outside the question's scale.
     */
    private function parseScaleAnswer(
        SubmittedAnswers $answers,
        QuestionDefinition $question,
        bool $required,
        ?string $invalidMessage = null,
    ): int|DiaryValidation|null {
        $field = $question->field();
        $raw = $answers->value($field);

        $genericMessage = $invalidMessage ?? sprintf(
            '%s must be a whole number from %d to %d.',
            $question->label(),
            (int) $question->scaleMin(),
            (int) $question->scaleMax(),
        );

        if ($raw === '') {
            if ($required) {
                return DiaryValidation::rejected($answers, self::ERROR_CODE, [
                    $field => $invalidMessage ?? sprintf('Please provide your %s.', mb_strtolower($question->label())),
                ]);
            }

            return null;
        }

        if (preg_match('/^-?\d+$/', $raw) !== 1) {
            return DiaryValidation::rejected($answers, self::ERROR_CODE, [
                $field => $genericMessage,
            ]);
        }

        $value = (int) $raw;

        if (!$question->scaleContains($value)) {
            return DiaryValidation::rejected($answers, self::ERROR_CODE, [
                $field => $genericMessage,
            ]);
        }

        return $value;
    }
}
