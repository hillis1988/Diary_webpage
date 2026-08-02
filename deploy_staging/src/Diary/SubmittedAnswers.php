<?php

declare(strict_types=1);

namespace Diary\Diary;

/**
 * What the user actually typed, exactly as the form sent it.
 *
 * Kept as strings, because a rejected submission has to go back to the page
 * with the answers still in it (error catalogue: "answers preserved"). A
 * half-parsed value object cannot do that: a mood rating of "eleven" has no
 * integer form, but it must still reappear in the field the user typed it into.
 *
 * Every question field is always present, so a template can read any of them
 * without checking first.
 */
final class SubmittedAnswers
{
    /**
     * @param array<string, string> $values every question field, plus the date field
     */
    private function __construct(private readonly array $values)
    {
    }

    /**
     * Read a submission out of raw request data, keeping only the fields the
     * question set defines and coercing each to the string a form would have
     * sent. Anything else in the request is ignored rather than trusted.
     *
     * @param array<string, mixed> $form
     */
    public static function fromForm(array $form): self
    {
        $values = [];

        foreach (self::allFields() as $field) {
            $values[$field] = self::asString($form[$field] ?? null);
        }

        return new self($values);
    }

    /**
     * @param array<string, string|int|null> $values
     */
    public static function of(array $values): self
    {
        return self::fromForm($values);
    }

    /**
     * A blank submission: what the entry form starts from.
     */
    public static function blank(): self
    {
        return self::fromForm([]);
    }

    /**
     * The date field as submitted, 'YYYY-MM-DD' when it is well formed.
     */
    public function date(): string
    {
        return $this->values[QuestionSet::DATE_FIELD];
    }

    /**
     * The answer to one field, or '' when it was left blank. Unknown fields
     * read as '' so a template cannot break on a typo.
     */
    public function value(string $field): string
    {
        return $this->values[$field] ?? '';
    }

    public function isBlank(string $field): bool
    {
        return $this->value($field) === '';
    }

    /**
     * Every submitted value, keyed by field: what a template repopulates from.
     *
     * @return array<string, string>
     */
    public function toArray(): array
    {
        return $this->values;
    }

    /**
     * The same submission with one field replaced, e.g. defaulting the date to
     * today before the form is first rendered.
     */
    public function with(string $field, string|int|null $value): self
    {
        if (!array_key_exists($field, $this->values)) {
            return $this;
        }

        $values = $this->values;
        $values[$field] = self::asString($value);

        return new self($values);
    }

    /**
     * @return list<string> the date field followed by every question field
     */
    public static function allFields(): array
    {
        return [QuestionSet::DATE_FIELD, ...QuestionSet::fields()];
    }

    private static function asString(mixed $value): string
    {
        if ($value === null || is_array($value) || is_object($value)) {
            // Nothing usable was submitted for this field; treat it as blank
            // rather than inventing a value from a shape we did not ask for.
            return '';
        }

        if (is_bool($value)) {
            return $value ? '1' : '';
        }

        return trim((string) $value);
    }
}
