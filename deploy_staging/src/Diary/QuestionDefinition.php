<?php

declare(strict_types=1);

namespace Diary\Diary;

use InvalidArgumentException;

/**
 * One structured diary question, expressed as data (Requirement 5.1).
 *
 * A definition carries everything its three consumers need: the field name the
 * form input and the encrypted payload both use, the prompt shown to the user,
 * the answer type and - for a scale - its bounds and point labels. Defining a
 * question once is what stops the form, the validator and the AI prompt from
 * drifting apart.
 */
final class QuestionDefinition
{
    /**
     * @param array<int, string> $scaleLabels scale point => label, for the points that carry one
     */
    private function __construct(
        private readonly string $field,
        private readonly string $label,
        private readonly string $prompt,
        private readonly QuestionType $type,
        private readonly bool $required,
        private readonly ?int $scaleMin,
        private readonly ?int $scaleMax,
        private readonly array $scaleLabels,
    ) {
    }

    /**
     * A bounded ordinal scale, e.g. mood rating 1-10 (Requirement 5.2).
     *
     * @param array<int, string> $scaleLabels labels for the points that carry one; a point
     *                                        without a label renders as its own number
     */
    public static function scale(
        string $field,
        string $label,
        string $prompt,
        bool $required,
        int $min,
        int $max,
        array $scaleLabels = [],
    ): self {
        if ($min >= $max) {
            throw new InvalidArgumentException(sprintf(
                'Question "%s" needs a scale whose minimum is below its maximum, got %d-%d.',
                $field,
                $min,
                $max
            ));
        }

        foreach (array_keys($scaleLabels) as $point) {
            if ($point < $min || $point > $max) {
                throw new InvalidArgumentException(sprintf(
                    'Question "%s" has a label for scale point %d, outside its range %d-%d.',
                    $field,
                    $point,
                    $min,
                    $max
                ));
            }
        }

        ksort($scaleLabels);

        return new self($field, $label, $prompt, QuestionType::Scale, $required, $min, $max, $scaleLabels);
    }

    /**
     * A free text question. Free text is always optional: a day with nothing to
     * say about it is still a day worth recording.
     */
    public static function freeText(string $field, string $label, string $prompt): self
    {
        return new self($field, $label, $prompt, QuestionType::FreeText, false, null, null, []);
    }

    /**
     * The form input name, which is also the key in the encrypted payload.
     */
    public function field(): string
    {
        return $this->field;
    }

    /**
     * Short name for the field, used in form labels and validation messages.
     */
    public function label(): string
    {
        return $this->label;
    }

    /**
     * The question as it is put to the user, and as it labels the answer in an
     * AI prompt.
     */
    public function prompt(): string
    {
        return $this->prompt;
    }

    public function type(): QuestionType
    {
        return $this->type;
    }

    public function isRequired(): bool
    {
        return $this->required;
    }

    public function isScale(): bool
    {
        return $this->type === QuestionType::Scale;
    }

    public function isFreeText(): bool
    {
        return $this->type === QuestionType::FreeText;
    }

    public function scaleMin(): ?int
    {
        return $this->scaleMin;
    }

    public function scaleMax(): ?int
    {
        return $this->scaleMax;
    }

    /**
     * Every point on the scale, low to high; empty for free text.
     *
     * @return list<int>
     */
    public function scalePoints(): array
    {
        if ($this->scaleMin === null || $this->scaleMax === null) {
            return [];
        }

        return range($this->scaleMin, $this->scaleMax);
    }

    /**
     * @return array<int, string>
     */
    public function scaleLabels(): array
    {
        return $this->scaleLabels;
    }

    /**
     * The label for one scale point, falling back to the number itself so a form
     * always has something to show against each choice.
     */
    public function labelForPoint(int $point): string
    {
        return $this->scaleLabels[$point] ?? (string) $point;
    }

    public function scaleContains(int $value): bool
    {
        if ($this->scaleMin === null || $this->scaleMax === null) {
            return false;
        }

        return $value >= $this->scaleMin && $value <= $this->scaleMax;
    }

    /**
     * How the scale reads end to end, e.g. "1 (very poor) to 5 (very good)".
     * Used in help text and in AI prompts so the model knows which end is good.
     */
    public function scaleDescription(): string
    {
        if ($this->scaleMin === null || $this->scaleMax === null) {
            return '';
        }

        $low = $this->scaleLabels[$this->scaleMin] ?? null;
        $high = $this->scaleLabels[$this->scaleMax] ?? null;

        if ($low === null || $high === null) {
            return sprintf('%d to %d', $this->scaleMin, $this->scaleMax);
        }

        return sprintf(
            '%d (%s) to %d (%s)',
            $this->scaleMin,
            mb_strtolower($low),
            $this->scaleMax,
            mb_strtolower($high)
        );
    }
}
