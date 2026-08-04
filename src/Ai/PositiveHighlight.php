<?php

declare(strict_types=1);

namespace Diary\Ai;

/**
 * One dated positive drawn from past diary content for the Bright Spots page.
 */
final class PositiveHighlight
{
    public function __construct(
        private readonly string $date,
        private readonly string $title,
        private readonly string $whyItMattered,
        private readonly string $keepGoing,
    ) {
    }

    public function date(): string
    {
        return $this->date;
    }

    public function title(): string
    {
        return $this->title;
    }

    public function whyItMattered(): string
    {
        return $this->whyItMattered;
    }

    public function keepGoing(): string
    {
        return $this->keepGoing;
    }
}
