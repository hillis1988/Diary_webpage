<?php

declare(strict_types=1);

namespace Diary\Tests\Unit\Ai;

use Diary\Ai\SummaryEntryContent;
use Diary\Support\LocalDate;
use PHPUnit\Framework\TestCase;

/**
 * SummaryEntryContent::jsonSerialize() produces the fixed Summary_Entry_Record
 * shape (Requirements 1.2, 1.7, 1.8): an exact key set, with null sleep
 * quality and empty-string text fields preserved rather than omitted.
 */
final class SummaryEntryContentTest extends TestCase
{
    public function testJsonSerializeReturnsExactlyTheExpectedKeySet(): void
    {
        $content = new SummaryEntryContent(
            LocalDate::of(2025, 1, 15),
            7,
            4,
            'Went for a walk',
            'Felt productive',
            'Calm',
        );

        $json = $content->jsonSerialize();

        self::assertSame(
            ['date', 'mood_rating', 'sleep_quality', 'events', 'thoughts', 'emotions'],
            array_keys($json)
        );
        self::assertSame([
            'date' => '2025-01-15',
            'mood_rating' => 7,
            'sleep_quality' => 4,
            'events' => 'Went for a walk',
            'thoughts' => 'Felt productive',
            'emotions' => 'Calm',
        ], $json);
    }

    public function testNullSleepQualitySerializesAsPresentKeyWithNullValue(): void
    {
        $content = new SummaryEntryContent(
            LocalDate::of(2025, 1, 15),
            7,
            null,
            'Went for a walk',
            'Felt productive',
            'Calm',
        );

        $json = $content->jsonSerialize();

        self::assertArrayHasKey('sleep_quality', $json);
        self::assertNull($json['sleep_quality']);
    }

    public function testEmptyStringTextFieldsSerializeAsPresentKeysWithEmptyStringValues(): void
    {
        $content = new SummaryEntryContent(
            LocalDate::of(2025, 1, 15),
            7,
            4,
            '',
            '',
            '',
        );

        $json = $content->jsonSerialize();

        self::assertArrayHasKey('events', $json);
        self::assertArrayHasKey('thoughts', $json);
        self::assertArrayHasKey('emotions', $json);
        self::assertSame('', $json['events']);
        self::assertSame('', $json['thoughts']);
        self::assertSame('', $json['emotions']);
    }
}
