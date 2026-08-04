<?php

declare(strict_types=1);

namespace Diary\Ai;

/**
 * `cbt_recommendations.status`.
 *
 * `Generated` carries an accepted {@see CbtRecommendation}; `Failed` means the
 * provider could not be reached, or its response did not pass shape validation
 * (Requirements 6.2, 6.3, 6.5). Either way the row keeps `provider`, `model` and
 * `attempt_count`, but only `Generated` carries a ciphertext.
 */
enum FeedbackStatus: string
{
    case Generated = 'generated';
    case Failed = 'failed';
}
