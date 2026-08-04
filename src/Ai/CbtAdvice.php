<?php

declare(strict_types=1);

namespace Diary\Ai;

/**
 * `CbtAdvice(pattern, distortions, balancedPerspective, nextAction)`
 * (design.md): the CBT-oriented advice payload nested inside a
 * {@see ProgressSummary}, distinct from the narrative and metrics fields.
 */
final class CbtAdvice
{
    public function __construct(
        private readonly string $pattern,
        private readonly string $distortions,
        private readonly string $balancedPerspective,
        private readonly string $nextAction,
    ) {
    }

    public function pattern(): string
    {
        return $this->pattern;
    }

    public function distortions(): string
    {
        return $this->distortions;
    }

    public function balancedPerspective(): string
    {
        return $this->balancedPerspective;
    }

    public function nextAction(): string
    {
        return $this->nextAction;
    }
}
