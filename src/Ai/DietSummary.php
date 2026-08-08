<?php

declare(strict_types=1);

namespace Diary\Ai;

/**
 * Closed dietitian narrative returned by {@see DietSummaryProvider}.
 */
final class DietSummary
{
    public function __construct(
        private readonly string $overview,
        private readonly string $patterns,
        private readonly string $moodLinks,
        private readonly string $suggestion,
    ) {
    }

    public function overview(): string
    {
        return $this->overview;
    }

    public function patterns(): string
    {
        return $this->patterns;
    }

    public function moodLinks(): string
    {
        return $this->moodLinks;
    }

    public function suggestion(): string
    {
        return $this->suggestion;
    }
}
