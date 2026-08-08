<?php

declare(strict_types=1);

namespace Diary\Diary;

/**
 * Reads repeating `food_meal[n][…]` form fields into a {@see FoodDiary}.
 *
 * Kept separate from {@see SubmittedAnswers}, which only handles the flat
 * question-set whitelist. Empty rows (no description) are dropped; a row with
 * a description but an invalid type is rejected with a field message keyed so
 * the Food tab can surface it.
 */
final class FoodMealsParser
{
    public const FORM_KEY = 'food_meal';

    public const TYPE_FIELD = 'type';
    public const DESCRIPTION_FIELD = 'description';
    public const NOTES_FIELD = 'notes';

    public const TOO_MANY_MESSAGE = 'Please keep the food diary to 12 meals or fewer for one day.';
    public const TYPE_MESSAGE = 'Please choose a meal type.';
    public const DESCRIPTION_MESSAGE = 'Please say what you ate, or clear the meal row.';

    /**
     * @param array<string, mixed> $form
     *
     * @return FoodDiary|array{0: string, 1: array<string, string>} FoodDiary on
     *         success, or [errorCode, fieldMessages] on rejection
     */
    public static function parse(array $form): FoodDiary|array
    {
        $raw = $form[self::FORM_KEY] ?? null;

        if ($raw === null || $raw === '') {
            return FoodDiary::empty();
        }

        if (!is_array($raw)) {
            return FoodDiary::empty();
        }

        if (count($raw) > FoodDiary::MAX_MEALS) {
            return [DiaryInputValidator::ERROR_CODE, [self::FORM_KEY => self::TOO_MANY_MESSAGE]];
        }

        $meals = [];
        $fieldMessages = [];

        foreach ($raw as $index => $row) {
            if (!is_array($row)) {
                continue;
            }

            $type = self::asString($row[self::TYPE_FIELD] ?? null);
            $description = self::asString($row[self::DESCRIPTION_FIELD] ?? null);
            $notes = self::asString($row[self::NOTES_FIELD] ?? null);

            if ($description === '' && $notes === '') {
                continue;
            }

            if ($description === '') {
                $fieldMessages[self::fieldId((int) $index, self::DESCRIPTION_FIELD)] = self::DESCRIPTION_MESSAGE;
                continue;
            }

            if (!in_array($type, FoodMeal::TYPES, true)) {
                $fieldMessages[self::fieldId((int) $index, self::TYPE_FIELD)] = self::TYPE_MESSAGE;
                continue;
            }

            $meals[] = FoodMeal::of($type, $description, $notes);
        }

        if ($fieldMessages !== []) {
            return [DiaryInputValidator::ERROR_CODE, $fieldMessages];
        }

        if (count($meals) > FoodDiary::MAX_MEALS) {
            return [DiaryInputValidator::ERROR_CODE, [self::FORM_KEY => self::TOO_MANY_MESSAGE]];
        }

        return FoodDiary::of($meals);
    }

    /**
     * Rows to redisplay in the form, including a blank trailing row when empty.
     *
     * @param array<string, mixed> $form
     *
     * @return list<array{type: string, description: string, notes: string}>
     */
    public static function rowsForRedisplay(array $form, ?FoodDiary $saved = null): array
    {
        $raw = $form[self::FORM_KEY] ?? null;

        if (is_array($raw) && $raw !== []) {
            $rows = [];
            foreach ($raw as $row) {
                if (!is_array($row)) {
                    continue;
                }

                $rows[] = [
                    self::TYPE_FIELD => self::asString($row[self::TYPE_FIELD] ?? null),
                    self::DESCRIPTION_FIELD => self::asString($row[self::DESCRIPTION_FIELD] ?? null),
                    self::NOTES_FIELD => self::asString($row[self::NOTES_FIELD] ?? null),
                ];
            }

            if ($rows !== []) {
                return $rows;
            }
        }

        if ($saved !== null && !$saved->isEmpty()) {
            return array_map(
                static fn (FoodMeal $meal): array => [
                    self::TYPE_FIELD => $meal->type(),
                    self::DESCRIPTION_FIELD => $meal->description(),
                    self::NOTES_FIELD => $meal->notes(),
                ],
                $saved->meals()
            );
        }

        return [[
            self::TYPE_FIELD => FoodMeal::TYPE_BREAKFAST,
            self::DESCRIPTION_FIELD => '',
            self::NOTES_FIELD => '',
        ]];
    }

    public static function fieldId(int $index, string $field): string
    {
        return self::FORM_KEY . '_' . $index . '_' . $field;
    }

    private static function asString(mixed $value): string
    {
        if ($value === null || is_array($value) || is_object($value)) {
            return '';
        }

        if (is_bool($value)) {
            return $value ? '1' : '';
        }

        return trim((string) $value);
    }
}
