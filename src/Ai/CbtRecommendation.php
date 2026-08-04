<?php

declare(strict_types=1);

namespace Diary\Ai;

/**
 * One CBT-style recommendation for a Diary_Entry: one positive focus to
 * concentrate on and one small, meaningful change to make (Requirements 6.2,
 * 6.3).
 *
 * This class only carries the two strings; whether they are non-empty enough
 * to accept is AI_Feedback_Service's shape-validation concern (task 10.2), not
 * this object's.
 */
final class CbtRecommendation
{
    public function __construct(
        private readonly string $positiveFocus,
        private readonly string $suggestedChange,
    ) {
    }

    public function positiveFocus(): string
    {
        return $this->positiveFocus;
    }

    public function suggestedChange(): string
    {
        return $this->suggestedChange;
    }
}
