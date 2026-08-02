<?php

declare(strict_types=1);

namespace Diary\Milestone;

/**
 * The closed set of milestone categories (Requirement 10.2).
 *
 * The value of each case is exactly the string written into the encrypted
 * payload's `category` field (see {@see \Diary\Storage\PayloadShape::MILESTONE_CATEGORIES}),
 * so this enum and the payload schema cannot drift apart.
 */
enum MilestoneCategory: string
{
    case Medication = 'medication';
    case Relationship = 'relationship';
    case Lifestyle = 'lifestyle';
    case Other = 'other';

    /**
     * @return list<string> every category value, in the order presented to the user
     */
    public static function values(): array
    {
        return array_map(static fn (self $category): string => $category->value, self::cases());
    }
}
