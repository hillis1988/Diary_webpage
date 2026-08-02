<?php

declare(strict_types=1);

namespace Diary\Diary;

use InvalidArgumentException;

/**
 * The structured question set the diary is built around (Requirement 5.1):
 * mood rating, sleep quality, notable events, thoughts, emotions.
 *
 * This is the single definition. The entry form renders it, the validator
 * checks against it, and the AI prompt labels the answers with it, so none of
 * the three can drift from the others. The field names are the keys of the
 * encrypted diary payload, which keeps the domain and the storage boundary
 * speaking the same language.
 *
 * Design decision - sleep quality scale: the requirements do not fix one, so a
 * 1-5 ordinal scale (very poor to very good) is used. It still yields a numeric
 * series for trend metrics (Requirement 9.2) while being quicker to answer than
 * a second 1-10 slider.
 */
final class QuestionSet
{
    /** The date the entry is for. Not a question, but part of the submission. */
    public const DATE_FIELD = 'entry_date';

    public const MOOD_RATING = 'mood_rating';
    public const SLEEP_QUALITY = 'sleep_quality';
    public const EVENTS = 'events';
    public const THOUGHTS = 'thoughts';
    public const EMOTIONS = 'emotions';

    /** Requirement 5.2: mood is rated on a defined numeric scale of 1 to 10. */
    public const MOOD_MIN = 1;
    public const MOOD_MAX = 10;

    public const SLEEP_MIN = 1;
    public const SLEEP_MAX = 5;

    /** @var list<QuestionDefinition>|null built once per process */
    private static ?array $definitions = null;

    /**
     * Every question, in the order the form asks them and the order the
     * validator reports problems in. Mood rating comes first because it is the
     * only required answer.
     *
     * @return list<QuestionDefinition>
     */
    public static function definitions(): array
    {
        return self::$definitions ??= [
            QuestionDefinition::scale(
                field: self::MOOD_RATING,
                label: 'Mood rating',
                prompt: 'How would you rate your mood today?',
                required: true,
                min: self::MOOD_MIN,
                max: self::MOOD_MAX,
                scaleLabels: [
                    self::MOOD_MIN => 'Very low',
                    self::MOOD_MAX => 'Very good',
                ],
            ),
            QuestionDefinition::scale(
                field: self::SLEEP_QUALITY,
                label: 'Sleep quality',
                prompt: 'How well did you sleep last night?',
                required: false,
                min: self::SLEEP_MIN,
                max: self::SLEEP_MAX,
                scaleLabels: [
                    1 => 'Very poor',
                    2 => 'Poor',
                    3 => 'Fair',
                    4 => 'Good',
                    5 => 'Very good',
                ],
            ),
            QuestionDefinition::freeText(
                field: self::EVENTS,
                label: 'Notable events',
                prompt: 'What happened today that felt notable?',
            ),
            QuestionDefinition::freeText(
                field: self::THOUGHTS,
                label: 'Thoughts',
                prompt: 'What thoughts have been going through your mind?',
            ),
            QuestionDefinition::freeText(
                field: self::EMOTIONS,
                label: 'Emotions',
                prompt: 'Which emotions did you notice, and how strong were they?',
            ),
        ];
    }

    /**
     * The field names of the question set, in order. These are exactly the
     * content keys of the encrypted diary payload.
     *
     * @return list<string>
     */
    public static function fields(): array
    {
        return array_map(
            static fn (QuestionDefinition $question): string => $question->field(),
            self::definitions()
        );
    }

    /**
     * @return list<string> the fields an entry cannot be saved without
     */
    public static function requiredFields(): array
    {
        return array_values(array_map(
            static fn (QuestionDefinition $question): string => $question->field(),
            array_filter(
                self::definitions(),
                static fn (QuestionDefinition $question): bool => $question->isRequired()
            )
        ));
    }

    public static function has(string $field): bool
    {
        return in_array($field, self::fields(), true);
    }

    /**
     * @throws InvalidArgumentException when no question uses that field name
     */
    public static function byField(string $field): QuestionDefinition
    {
        foreach (self::definitions() as $question) {
            if ($question->field() === $field) {
                return $question;
            }
        }

        throw new InvalidArgumentException(sprintf('The diary question set has no field "%s".', $field));
    }

    public static function moodRating(): QuestionDefinition
    {
        return self::byField(self::MOOD_RATING);
    }

    public static function sleepQuality(): QuestionDefinition
    {
        return self::byField(self::SLEEP_QUALITY);
    }
}
