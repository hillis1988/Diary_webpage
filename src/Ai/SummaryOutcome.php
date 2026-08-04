<?php

declare(strict_types=1);

namespace Diary\Ai;

use LogicException;

/**
 * `SummaryOutcome = Summary(text, metrics) | InsufficientData | Unavailable(reason)`
 * (design.md), as returned by {@see AiSummaryService::summarise()}
 * (Requirements 9.1-9.5).
 *
 * Three shapes, made by three named constructors and nothing else, mirroring
 * {@see FeedbackOutcome}'s closed-shape pattern. Only `Summary` carries a
 * {@see ProgressSummary}; `InsufficientData` and `Unavailable` carry only the
 * fixed, non-technical wording Requirements 9.4 and 9.5 ask the Diary_App to
 * display.
 *
 * Outcome precedence is explicit (design.md): `summarise()` checks the entry
 * count first. Fewer than three entries always yields `InsufficientData`,
 * even if the provider would also have failed (Requirement 9.4). Only with
 * three or more entries does a provider failure surface as `Unavailable`
 * (Requirement 9.5).
 */
final class SummaryOutcome
{
    /** Requirement 9.4's fixed wording, including when summary generation also fails. */
    public const INSUFFICIENT_DATA_MESSAGE = 'More entries are needed to produce a reliable summary';

    /** Requirement 9.5's fixed wording for a failed or unreachable provider. */
    public const UNAVAILABLE_MESSAGE = 'The summary is temporarily unavailable';

    private const STATE_SUMMARY = 'summary';
    private const STATE_INSUFFICIENT_DATA = 'insufficient_data';
    private const STATE_UNAVAILABLE = 'unavailable';

    private function __construct(
        private readonly string $state,
        private readonly ?ProgressSummary $summary,
        private readonly ?string $reason,
    ) {
    }

    public static function summary(ProgressSummary $summary): self
    {
        return new self(self::STATE_SUMMARY, $summary, null);
    }

    public static function insufficientData(string $reason = self::INSUFFICIENT_DATA_MESSAGE): self
    {
        return new self(self::STATE_INSUFFICIENT_DATA, null, $reason);
    }

    public static function unavailable(string $reason = self::UNAVAILABLE_MESSAGE): self
    {
        return new self(self::STATE_UNAVAILABLE, null, $reason);
    }

    public function isSummary(): bool
    {
        return $this->state === self::STATE_SUMMARY;
    }

    public function isInsufficientData(): bool
    {
        return $this->state === self::STATE_INSUFFICIENT_DATA;
    }

    public function isUnavailable(): bool
    {
        return $this->state === self::STATE_UNAVAILABLE;
    }

    public function summaryValue(): ProgressSummary
    {
        if (!$this->isSummary()) {
            throw new LogicException('A non-summary outcome carries no summary; check isSummary() first.');
        }

        return $this->summary;
    }

    /**
     * The user-facing message on an insufficient-data or unavailable
     * outcome; null on success.
     */
    public function reason(): ?string
    {
        return $this->reason;
    }
}
