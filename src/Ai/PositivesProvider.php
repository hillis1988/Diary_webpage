<?php

declare(strict_types=1);

namespace Diary\Ai;

/**
 * Contract for generating optimistic, friend-toned reminders from past diary
 * content for the Bright Spots page.
 */
interface PositivesProvider
{
    public function generate(SummaryInput $input): PositivesReminder;
}
