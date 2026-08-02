<?php

declare(strict_types=1);

namespace Diary\Milestone;

/**
 * What the user actually typed for a milestone, exactly as the form sent it.
 *
 * Mirrors {@see \Diary\Diary\SubmittedAnswers}: kept as strings because a
 * rejected submission has to go back to the page with the answers still in
 * it, and a category the user mistyped or a blank description has no
 * validated form to preserve.
 */
final class MilestoneSubmission
{
    public const DATE_FIELD = 'date';
    public const DESCRIPTION_FIELD = 'description';
    public const CATEGORY_FIELD = 'category';

    private function __construct(
        private readonly string $date,
        private readonly string $description,
        private readonly string $category,
    ) {
    }

    /**
     * Read a submission out of raw request data, coercing each field to the
     * string a form would have sent. Anything else in the request is ignored
     * rather than trusted.
     *
     * @param array<string, mixed> $form
     */
    public static function fromForm(array $form): self
    {
        return new self(
            self::asString($form[self::DATE_FIELD] ?? null),
            self::asString($form[self::DESCRIPTION_FIELD] ?? null),
            self::asString($form[self::CATEGORY_FIELD] ?? null),
        );
    }

    /**
     * @param array<string, string> $values
     */
    public static function of(array $values): self
    {
        return self::fromForm($values);
    }

    public static function blank(): self
    {
        return self::fromForm([]);
    }

    public function date(): string
    {
        return $this->date;
    }

    public function description(): string
    {
        return $this->description;
    }

    public function category(): string
    {
        return $this->category;
    }

    /**
     * @return array<string, string>
     */
    public function toArray(): array
    {
        return [
            self::DATE_FIELD => $this->date,
            self::DESCRIPTION_FIELD => $this->description,
            self::CATEGORY_FIELD => $this->category,
        ];
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
