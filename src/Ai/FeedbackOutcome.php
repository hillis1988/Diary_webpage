<?php

declare(strict_types=1);

namespace Diary\Ai;

use LogicException;

/**
 * What {@see AiFeedbackService::generateForEntry()} and {@see
 * AiFeedbackService::retry()} return: `Generated(CbtRecommendation)` or
 * `Unavailable(reason)`, as named in the design (Requirements 6.1-6.5).
 *
 * Two shapes, made by two named constructors and nothing else, so a caller
 * handling both has handled every case. `Unavailable` never carries an
 * exception or a technical detail - only the fixed, non-technical wording
 * Requirement 6.5 asks the Diary_App to display; the diary entry itself is
 * never affected by this outcome; it was already committed before this
 * service ran.
 */
final class FeedbackOutcome
{
    /** Requirement 6.5's fixed wording for a failed or unreachable provider. */
    public const UNAVAILABLE_MESSAGE = 'Feedback is temporarily unavailable';

    private function __construct(
        private readonly bool $generated,
        private readonly ?CbtRecommendation $recommendation,
        private readonly ?string $reason,
    ) {
    }

    public static function generated(CbtRecommendation $recommendation): self
    {
        return new self(true, $recommendation, null);
    }

    public static function unavailable(string $reason = self::UNAVAILABLE_MESSAGE): self
    {
        return new self(false, null, $reason);
    }

    public function isGenerated(): bool
    {
        return $this->generated;
    }

    public function isUnavailable(): bool
    {
        return !$this->generated;
    }

    public function recommendation(): CbtRecommendation
    {
        if (!$this->generated) {
            throw new LogicException('An unavailable outcome carries no recommendation; check isGenerated() first.');
        }

        return $this->recommendation;
    }

    /**
     * The user-facing message on an unavailable outcome; null on success.
     */
    public function reason(): ?string
    {
        return $this->reason;
    }
}
