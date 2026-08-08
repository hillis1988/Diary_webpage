<?php

declare(strict_types=1);

namespace Diary\Diary;

use InvalidArgumentException;

/**
 * One meal logged in an optional food diary for a day.
 *
 * Types are a closed set so the dietitian summary prompt and the encrypted
 * payload schema stay aligned. Description is required once a meal is kept;
 * notes are free text and may be blank.
 */
final class FoodMeal
{
    public const TYPE_BREAKFAST = 'breakfast';
    public const TYPE_LUNCH = 'lunch';
    public const TYPE_DINNER = 'dinner';
    public const TYPE_SNACK = 'snack';
    public const TYPE_OTHER = 'other';

    /** @var list<string> */
    public const TYPES = [
        self::TYPE_BREAKFAST,
        self::TYPE_LUNCH,
        self::TYPE_DINNER,
        self::TYPE_SNACK,
        self::TYPE_OTHER,
    ];

    /** Display labels for the form and calendar. */
    public const TYPE_LABELS = [
        self::TYPE_BREAKFAST => 'Breakfast',
        self::TYPE_LUNCH => 'Lunch',
        self::TYPE_DINNER => 'Dinner',
        self::TYPE_SNACK => 'Snack',
        self::TYPE_OTHER => 'Other',
    ];

    private function __construct(
        private readonly string $type,
        private readonly string $description,
        private readonly string $notes,
    ) {
    }

    public static function of(string $type, string $description, string $notes = ''): self
    {
        if (!in_array($type, self::TYPES, true)) {
            throw new InvalidArgumentException(sprintf('Unknown meal type "%s".', $type));
        }

        $description = trim($description);
        if ($description === '') {
            throw new InvalidArgumentException('A food meal requires a description.');
        }

        return new self($type, $description, trim($notes));
    }

    public function type(): string
    {
        return $this->type;
    }

    public function typeLabel(): string
    {
        return self::TYPE_LABELS[$this->type] ?? $this->type;
    }

    public function description(): string
    {
        return $this->description;
    }

    public function notes(): string
    {
        return $this->notes;
    }

    /**
     * @return array{type: string, description: string, notes: string}
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'description' => $this->description,
            'notes' => $this->notes,
        ];
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromArray(array $row): self
    {
        $type = is_string($row['type'] ?? null) ? $row['type'] : '';
        $description = is_string($row['description'] ?? null) ? $row['description'] : '';
        $notes = is_string($row['notes'] ?? null) ? $row['notes'] : '';

        return self::of($type, $description, $notes);
    }
}
