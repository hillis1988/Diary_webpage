<?php

declare(strict_types=1);

namespace Diary\Diary;

/**
 * The kinds of answer a structured diary question can take (Requirement 5.1).
 *
 * The set is closed on purpose: the form renderer, the validator and the AI
 * prompt builder each switch on it, so a new kind is a deliberate change in
 * three known places rather than an unhandled string arriving at runtime.
 */
enum QuestionType: string
{
    /** A whole number on a bounded ordinal scale, e.g. mood 1-10, sleep 1-5. */
    case Scale = 'scale';

    /** Free text, as long or as short as the user wants it. */
    case FreeText = 'free_text';
}
