<?php

declare(strict_types=1);

namespace Diary\Ai;

use LogicException;

/**
 * Outcome of the optional dietitian analysis on the progress summary page.
 *
 * `skipped` means the range had no food data - the page omits the diet card
 * entirely. `unavailable` means the provider failed after food data existed.
 */
final class DietSummaryOutcome
{
    public const UNAVAILABLE_MESSAGE = 'The dietitian notes are temporarily unavailable. Your CBT summary above is still based on your diary entries.';

    public const DISCLAIMER_MESSAGE = 'These notes are automated dietary reflections and are not a substitute for professional medical or nutrition advice.';

    private const STATE_NOTES = 'notes';
    private const STATE_SKIPPED = 'skipped';
    private const STATE_UNAVAILABLE = 'unavailable';

    private function __construct(
        private readonly string $state,
        private readonly ?DietSummary $summary,
        private readonly ?string $reason,
    ) {
    }

    public static function notes(DietSummary $summary): self
    {
        return new self(self::STATE_NOTES, $summary, null);
    }

    public static function skipped(): self
    {
        return new self(self::STATE_SKIPPED, null, null);
    }

    public static function unavailable(string $reason = self::UNAVAILABLE_MESSAGE): self
    {
        return new self(self::STATE_UNAVAILABLE, null, $reason);
    }

    public function isNotes(): bool
    {
        return $this->state === self::STATE_NOTES;
    }

    public function isSkipped(): bool
    {
        return $this->state === self::STATE_SKIPPED;
    }

    public function isUnavailable(): bool
    {
        return $this->state === self::STATE_UNAVAILABLE;
    }

    public function summaryValue(): DietSummary
    {
        if (!$this->isNotes()) {
            throw new LogicException('A non-notes diet outcome carries no summary; check isNotes() first.');
        }

        /** @var DietSummary $summary */
        $summary = $this->summary;

        return $summary;
    }

    public function reason(): ?string
    {
        return $this->reason;
    }
}
