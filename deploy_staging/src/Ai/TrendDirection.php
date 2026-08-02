<?php

declare(strict_types=1);

namespace Diary\Ai;

/**
 * The direction a mood or sleep series is moving in, as computed by
 * {@see TrendCalculator} from a least-squares slope over the series
 * (Requirement 9.2).
 */
enum TrendDirection: string
{
    case Improving = 'improving';
    case Declining = 'declining';
    case Stable = 'stable';
}
