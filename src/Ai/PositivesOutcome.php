<?php

declare(strict_types=1);

namespace Diary\Ai;

use LogicException;

/**
 * Outcome of {@see AiPositivesService::remind()}: a reminder, insufficient
 * data, or an unavailable provider.
 */
final class PositivesOutcome
{
    public const INSUFFICIENT_DATA_MESSAGE = 'A few more diary entries will give this page richer moments to celebrate with you.';

    public const UNAVAILABLE_MESSAGE = 'Your bright spots are temporarily unavailable. Please try again in a little while.';

    private const STATE_REMINDER = 'reminder';
    private const STATE_INSUFFICIENT_DATA = 'insufficient_data';
    private const STATE_UNAVAILABLE = 'unavailable';

    private function __construct(
        private readonly string $state,
        private readonly ?PositivesReminder $reminder,
        private readonly ?string $reason,
    ) {
    }

    public static function reminder(PositivesReminder $reminder): self
    {
        return new self(self::STATE_REMINDER, $reminder, null);
    }

    public static function insufficientData(string $reason = self::INSUFFICIENT_DATA_MESSAGE): self
    {
        return new self(self::STATE_INSUFFICIENT_DATA, null, $reason);
    }

    public static function unavailable(string $reason = self::UNAVAILABLE_MESSAGE): self
    {
        return new self(self::STATE_UNAVAILABLE, null, $reason);
    }

    public function isReminder(): bool
    {
        return $this->state === self::STATE_REMINDER;
    }

    public function isInsufficientData(): bool
    {
        return $this->state === self::STATE_INSUFFICIENT_DATA;
    }

    public function isUnavailable(): bool
    {
        return $this->state === self::STATE_UNAVAILABLE;
    }

    public function reminderValue(): PositivesReminder
    {
        if (!$this->isReminder()) {
            throw new LogicException('A non-reminder outcome carries no reminder; check isReminder() first.');
        }

        return $this->reminder;
    }

    public function reason(): ?string
    {
        return $this->reason;
    }
}
