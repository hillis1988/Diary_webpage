<?php

declare(strict_types=1);

namespace Diary\Storage;

/**
 * The three encrypted payload documents the schema defines, one per sensitive
 * table (Requirements 4.1, 4.2).
 *
 * A shape is identified by its table name because the table name is half of the
 * additional authenticated data binding a ciphertext to its row; keeping the two
 * as one value makes it impossible to encode a diary payload while binding it to
 * the milestones table.
 *
 * The case list is closed on purpose. A new sensitive table is a schema change,
 * a migration, and a decision about `schema_version`, not something a caller
 * should be able to invent by passing a string.
 */
enum PayloadShape: string
{
    case DiaryEntry = 'diary_entries';
    case CbtRecommendation = 'cbt_recommendations';
    case Milestone = 'milestones';

    /** The categories a milestone may carry; encrypted, because "medication" is health information. */
    public const MILESTONE_CATEGORIES = ['medication', 'relationship', 'lifestyle', 'other'];

    /**
     * The table whose `key_id`, `nonce` and `payload_ciphertext` columns hold
     * this payload, and the table half of the record binding.
     */
    public function table(): string
    {
        return $this->value;
    }

    /**
     * Every key this payload may contain, `schema_version` included.
     *
     * Used to reject a payload carrying an unknown key, which is nearly always a
     * typo or a field that was meant to be stored and would otherwise be
     * silently dropped.
     *
     * @return list<string>
     */
    public function fields(): array
    {
        return match ($this) {
            self::DiaryEntry => [
                'mood_rating',
                'sleep_quality',
                'events',
                'thoughts',
                'emotions',
                'schema_version',
            ],
            self::CbtRecommendation => [
                'positive_focus',
                'suggested_change',
                'schema_version',
            ],
            self::Milestone => [
                'description',
                'category',
                'schema_version',
            ],
        };
    }

    public static function forTable(string $table): self
    {
        $shape = self::tryFrom($table);

        if ($shape === null) {
            throw new PayloadException(sprintf('There is no encrypted payload shape for table "%s".', $table));
        }

        return $shape;
    }
}
