<?php

declare(strict_types=1);

namespace Diary\Milestone;

use Diary\Support\Result;
use LogicException;

/**
 * The outcome of validating a milestone submission (Requirement 10.4).
 *
 * Mirrors {@see \Diary\Diary\DiaryValidation}: whether accepted or rejected,
 * the outcome always carries the submitted values back, so a rejected
 * submission can be redisplayed exactly as typed and nothing is written. A
 * rejection carries a per-field message naming the offending field, and
 * {@see result()} exposes the same failure as the `Result` every other
 * service returns.
 */
final class MilestoneValidation
{
    /**
     * @param array<string, string> $fieldMessages field name => user-facing message
     */
    private function __construct(
        private readonly ?MilestoneInput $input,
        private readonly MilestoneSubmission $submission,
        private readonly ?string $errorCode,
        private readonly ?string $message,
        private readonly array $fieldMessages,
    ) {
    }

    public static function accepted(MilestoneInput $input, MilestoneSubmission $submission): self
    {
        return new self($input, $submission, null, null, []);
    }

    /**
     * @param array<string, string> $fieldMessages at least one; the first is the summary message
     */
    public static function rejected(MilestoneSubmission $submission, string $errorCode, array $fieldMessages): self
    {
        if ($fieldMessages === []) {
            throw new LogicException('A rejected milestone submission needs at least one field message.');
        }

        return new self(
            null,
            $submission,
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
    public function input(): MilestoneInput
    {
        if ($this->input === null) {
            throw new LogicException(sprintf(
                'Cannot read the input of a rejected milestone submission (%s).',
                (string) $this->errorCode
            ));
        }

        return $this->input;
    }

    /**
     * What the user submitted, for redisplay. Present on acceptance too, so one
     * code path can repopulate the form either way.
     */
    public function submission(): MilestoneSubmission
    {
        return $this->submission;
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
     * Whether the rejection names a given field (Requirement 10.4).
     */
    public function namesField(string $field): bool
    {
        return isset($this->fieldMessages[$field]);
    }

    /**
     * The same outcome as the `Result` the rest of the services speak.
     *
     * @return Result<MilestoneInput>|Result<null>
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
