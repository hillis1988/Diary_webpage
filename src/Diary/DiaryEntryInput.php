<?php

declare(strict_types=1);

namespace Diary\Diary;

use Diary\Support\LocalDate;
use InvalidArgumentException;

/**
 * A diary submission that has already been validated: the shape
 * `Diary_Service::submitEntry()` accepts.
 *
 * Holding only valid answers is the point. Once an input exists, the mood rating
 * is an integer from 1 to 10 (Requirement 5.2) and the sleep quality is either
 * absent or an integer from 1 to 5, so nothing downstream - upsert, encryption,
 * AI prompt - has to re-check them. User-entered text that failed validation
 * never reaches this class; it stays in {@see SubmittedAnswers}.
 *
 * Construction throws rather than returning a Result, because arriving here with
 * invalid values means a caller skipped {@see DiaryInputValidator}, which is a
 * programming mistake, not a user one.
 */
final class DiaryEntryInput
{
    private function __construct(
        private readonly LocalDate $date,
        private readonly int $moodRating,
        private readonly ?int $sleepQuality,
        private readonly string $events,
        private readonly string $thoughts,
        private readonly string $emotions,
    ) {
    }

    public static function of(
        LocalDate $date,
        int $moodRating,
        ?int $sleepQuality = null,
        string $events = '',
        string $thoughts = '',
        string $emotions = '',
    ): self {
        self::assertOnScale(QuestionSet::moodRating(), $moodRating);

        if ($sleepQuality !== null) {
            self::assertOnScale(QuestionSet::sleepQuality(), $sleepQuality);
        }

        return new self($date, $moodRating, $sleepQuality, $events, $thoughts, $emotions);
    }

    public function date(): LocalDate
    {
        return $this->date;
    }

    /** Always an integer from 1 to 10 (Requirement 5.2). */
    public function moodRating(): int
    {
        return $this->moodRating;
    }

    /** An integer from 1 to 5, or null when the question was left blank. */
    public function sleepQuality(): ?int
    {
        return $this->sleepQuality;
    }

    public function events(): string
    {
        return $this->events;
    }

    public function thoughts(): string
    {
        return $this->thoughts;
    }

    public function emotions(): string
    {
        return $this->emotions;
    }

    /**
     * The answer to one question by field name, so a caller iterating the
     * question set does not need a switch of its own.
     */
    public function answer(string $field): string|int|null
    {
        return match ($field) {
            QuestionSet::MOOD_RATING => $this->moodRating,
            QuestionSet::SLEEP_QUALITY => $this->sleepQuality,
            QuestionSet::EVENTS => $this->events,
            QuestionSet::THOUGHTS => $this->thoughts,
            QuestionSet::EMOTIONS => $this->emotions,
            default => throw new InvalidArgumentException(
                sprintf('The diary question set has no field "%s".', $field)
            ),
        };
    }

    /**
     * The content half of the encrypted diary payload, in question-set order.
     * `schema_version` is the storage layer's business and is not added here.
     *
     * @return array<string, string|int|null>
     */
    public function toPayload(): array
    {
        $payload = [];

        foreach (QuestionSet::fields() as $field) {
            $payload[$field] = $this->answer($field);
        }

        return $payload;
    }

    /**
     * The input back in submitted form, for redisplaying a saved entry in the
     * form it was entered through.
     */
    public function toSubmittedAnswers(): SubmittedAnswers
    {
        return SubmittedAnswers::of([
            QuestionSet::DATE_FIELD => $this->date->toIso(),
            ...$this->toPayload(),
        ]);
    }

    private static function assertOnScale(QuestionDefinition $question, int $value): void
    {
        if (!$question->scaleContains($value)) {
            throw new InvalidArgumentException(sprintf(
                '%s must be a whole number from %d to %d, got %d.',
                $question->label(),
                (int) $question->scaleMin(),
                (int) $question->scaleMax(),
                $value
            ));
        }
    }
}
