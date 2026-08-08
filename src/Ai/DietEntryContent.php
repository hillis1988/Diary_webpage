<?php

declare(strict_types=1);

namespace Diary\Ai;

use Diary\Diary\DiaryEntry;
use Diary\Diary\FoodMeal;
use Diary\Support\LocalDate;
use JsonSerializable;

/**
 * One day's content for the dietitian summary: mood/sleep context plus any
 * meals logged that day. Pseudonymised the same way as {@see SummaryEntryContent}.
 */
final class DietEntryContent implements JsonSerializable
{
    /**
     * @param list<array{type: string, description: string, notes: string}> $foodMeals
     */
    public function __construct(
        private readonly LocalDate $date,
        private readonly int $moodRating,
        private readonly ?int $sleepQuality,
        private readonly string $events,
        private readonly string $thoughts,
        private readonly string $emotions,
        private readonly array $foodMeals,
    ) {
    }

    public static function fromEntry(DiaryEntry $entry): self
    {
        $input = $entry->input();

        return new self(
            $entry->date(),
            $input->moodRating(),
            $input->sleepQuality(),
            $input->events(),
            $input->thoughts(),
            $input->emotions(),
            array_map(
                static fn (FoodMeal $meal): array => $meal->toArray(),
                $input->foodDiary()->meals()
            ),
        );
    }

    public function date(): LocalDate
    {
        return $this->date;
    }

    public function hasFood(): bool
    {
        return $this->foodMeals !== [];
    }

    /**
     * @return array{
     *     date: string,
     *     mood_rating: int,
     *     sleep_quality: ?int,
     *     events: string,
     *     thoughts: string,
     *     emotions: string,
     *     food_meals: list<array{type: string, description: string, notes: string}>
     * }
     */
    public function jsonSerialize(): array
    {
        return [
            'date' => $this->date->toIso(),
            'mood_rating' => $this->moodRating,
            'sleep_quality' => $this->sleepQuality,
            'events' => $this->events,
            'thoughts' => $this->thoughts,
            'emotions' => $this->emotions,
            'food_meals' => $this->foodMeals,
        ];
    }
}
