<?php

declare(strict_types=1);

namespace Diary\Diary;

use Diary\Support\Result;
use LogicException;

/**
 * The outcome of validating a diary submission.
 *
 * Whether it was accepted or rejected, the outcome always carries the submitted
 * answers back. That is deliberate: Requirement 5.5 rejects a submission without
 * saving anything, and the error catalogue promises the user's answers are
 * preserved, so preservation belongs in this API rather than in whatever
 * controller happens to handle the post.
 *
 * A rejection also carries a per-field message, so the form can render the
 * problem beside the field that caused it, and {@see result()} exposes the same
 * failure as the `Result` every other service returns.
 *
 * @phpstan-type FieldMessages array<string, string>
 */
final class DiaryValidation
{
    /**
     * @param array<string, string> $fieldMessages field name => user-facing message
     */
    private function __construct(
        private readonly ?DiaryEntryInput $input,
        private readonly SubmittedAnswers $answers,
        private readonly ?string $errorCode,
        private readonly ?string $message,
        private readonly array $fieldMessages,
    ) {
    }

    public static function accepted(DiaryEntryInput $input, SubmittedAnswers $answers): self
    {
        return new self($input, $answers, null, null, []);
    }

    /**
     * @param array<string, string> $fieldMessages at least one; the first is the summary message
     */
    public static function rejected(SubmittedAnswers $answers, string $errorCode, array $fieldMessages): self
    {
        if ($fieldMessages === []) {
            throw new LogicException('A rejected diary submission needs at least one field message.');
        }

        return new self(
            null,
            $answers,
            $errorCode,
            (string) reset($fieldMessages),
            $fieldMessages
        );
    }

    public function isAccepted(): bool
    {
        return $this->input !== null;
    }

    public function isRejected(): bool
    {
        return $this->input === null;
    }

    /**
     * The validated input, ready to store.
     *
     * @throws LogicException when the submission was rejected
     */
    public function input(): DiaryEntryInput
    {
        if ($this->input === null) {
            throw new LogicException(sprintf(
                'Cannot read the input of a rejected diary submission (%s).',
                (string) $this->errorCode
            ));
        }

        return $this->input;
    }

    /**
     * What the user submitted, for redisplay. Present on acceptance too, so one
     * code path can repopulate the form either way.
     */
    public function answers(): SubmittedAnswers
    {
        return $this->answers;
    }

    public function errorCode(): ?string
    {
        return $this->errorCode;
    }

    /**
     * The summary message shown at the top of the form; null on acceptance.
     */
    public function message(): ?string
    {
        return $this->message;
    }

    /**
     * @return array<string, string>
     */
    public function fieldMessages(): array
    {
        return $this->fieldMessages;
    }

    public function fieldMessage(string $field): ?string
    {
        return $this->fieldMessages[$field] ?? null;
    }

    /**
     * Whether the rejection names a given field, e.g. the mood rating
     * (Requirement 5.5).
     */
    public function namesField(string $field): bool
    {
        return isset($this->fieldMessages[$field]);
    }

    /**
     * The same outcome as the `Result` the rest of the services speak.
     *
     * @return Result<DiaryEntryInput>|Result<null>
     */
    public function result(): Result
    {
        if ($this->input !== null) {
            return Result::ok($this->input);
        }

        return Result::failure(
            (string) $this->errorCode,
            (string) $this->message,
            $this->fieldMessages
        );
    }
}
