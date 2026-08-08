<?php

declare(strict_types=1);

namespace Diary\Diary;

/**
 * The optional food diary for one day: zero or more {@see FoodMeal} rows.
 *
 * Empty diaries are first-class (not null) so a decoded payload always has a
 * stable shape and the form can redisplay an empty Food tab without special cases.
 */
final class FoodDiary
{
    /** Soft cap so a single day's encrypted payload stays bounded. */
    public const MAX_MEALS = 12;

    /**
     * @param list<FoodMeal> $meals
     */
    private function __construct(private readonly array $meals)
    {
    }

    /**
     * @param list<FoodMeal> $meals
     */
    public static function of(array $meals): self
    {
        if (count($meals) > self::MAX_MEALS) {
            throw new \InvalidArgumentException(sprintf(
                'A food diary may hold at most %d meals.',
                self::MAX_MEALS
            ));
        }

        return new self(array_values($meals));
    }

    public static function empty(): self
    {
        return new self([]);
    }

    public function isEmpty(): bool
    {
        return $this->meals === [];
    }

    /**
     * @return list<FoodMeal>
     */
    public function meals(): array
    {
        return $this->meals;
    }

    /**
     * @return list<array{type: string, description: string, notes: string}>
     */
    public function toPayload(): array
    {
        return array_map(static fn (FoodMeal $meal): array => $meal->toArray(), $this->meals);
    }

    /**
     * @param list<mixed>|null $rows
     */
    public static function fromPayload(?array $rows): self
    {
        if ($rows === null || $rows === []) {
            return self::empty();
        }

        $meals = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            /** @var array<string, mixed> $row */
            $meals[] = FoodMeal::fromArray($row);
        }

        return self::of($meals);
    }
}
