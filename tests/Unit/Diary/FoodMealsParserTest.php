<?php

declare(strict_types=1);

namespace Diary\Tests\Unit\Diary;

use Diary\Diary\DiaryInputValidator;
use Diary\Diary\FoodDiary;
use Diary\Diary\FoodMeal;
use Diary\Diary\FoodMealsParser;
use Diary\Diary\QuestionSet;
use Diary\Diary\SubmittedAnswers;
use PHPUnit\Framework\TestCase;

final class FoodMealsParserTest extends TestCase
{
    public function testEmptyFormYieldsEmptyFoodDiary(): void
    {
        $result = FoodMealsParser::parse([]);

        self::assertInstanceOf(FoodDiary::class, $result);
        self::assertTrue($result->isEmpty());
    }

    public function testBlankRowsAreDiscarded(): void
    {
        $result = FoodMealsParser::parse([
            FoodMealsParser::FORM_KEY => [
                [
                    'type' => 'breakfast',
                    'description' => '',
                    'notes' => '',
                ],
                [
                    'type' => 'lunch',
                    'description' => 'Soup',
                    'notes' => 'Warming',
                ],
            ],
        ]);

        self::assertInstanceOf(FoodDiary::class, $result);
        self::assertCount(1, $result->meals());
        self::assertSame('lunch', $result->meals()[0]->type());
        self::assertSame('Soup', $result->meals()[0]->description());
    }

    public function testInvalidTypeIsRejected(): void
    {
        $result = FoodMealsParser::parse([
            FoodMealsParser::FORM_KEY => [
                [
                    'type' => 'brunch',
                    'description' => 'Eggs',
                    'notes' => '',
                ],
            ],
        ]);

        self::assertIsArray($result);
        self::assertSame(DiaryInputValidator::ERROR_CODE, $result[0]);
        self::assertArrayHasKey(FoodMealsParser::fieldId(0, 'type'), $result[1]);
    }

    public function testValidatorAcceptsFoodMealsAlongsideJournalAnswers(): void
    {
        $form = [
            QuestionSet::DATE_FIELD => '2025-06-01',
            QuestionSet::MOOD_RATING => '7',
            QuestionSet::SLEEP_QUALITY => '3',
            QuestionSet::EVENTS => 'Walk',
            QuestionSet::THOUGHTS => '',
            QuestionSet::EMOTIONS => '',
            FoodMealsParser::FORM_KEY => [
                [
                    'type' => FoodMeal::TYPE_DINNER,
                    'description' => 'Pasta',
                    'notes' => '',
                ],
            ],
        ];

        $validation = (new DiaryInputValidator())->validate(SubmittedAnswers::fromForm($form), $form);

        self::assertTrue($validation->isAccepted());
        self::assertFalse($validation->input()->foodDiary()->isEmpty());
        self::assertSame('Pasta', $validation->input()->foodDiary()->meals()[0]->description());
    }
}
