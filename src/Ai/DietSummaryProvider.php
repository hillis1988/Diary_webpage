<?php

declare(strict_types=1);

namespace Diary\Ai;

interface DietSummaryProvider
{
    /**
     * @throws ProviderError on configuration, transport, or parse failure
     */
    public function generate(DietSummaryInput $input): DietSummary;
}
