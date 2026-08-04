<?php

declare(strict_types=1);

namespace Diary\Tests\Unit\Ai;

use Diary\Ai\SummaryMilestoneContent;
use Diary\Milestone\MilestoneCategory;
use Diary\Support\LocalDate;
use PHPUnit\Framework\TestCase;

/**
 * SummaryMilestoneContent::jsonSerialize() produces the fixed
 * Summary_Milestone_Record shape (Requirements 1.3, 1.7, 1.8): an exact key
 * set, with the empty-string description preserved rather than omitted.
 */
final class SummaryMilestoneContentTest extends TestCase
{
    public function testJsonSerializeReturnsExactlyTheExpectedKeySet(): void
    {
        $content = new SummaryMilestoneContent(
            LocalDate::of(2025, 1, 15),
            'Started a new medication',
            MilestoneCategory::Medication,
        );

        $json = $content->jsonSerialize();

        self::assertSame(
            ['date', 'category', 'description'],
            array_keys($json)
        );
        self::assertSame([
            'date' => '2025-01-15',
            'category' => 'medication',
            'description' => 'Started a new medication',
        ], $json);
    }

    public function testEmptyStringDescriptionSerializesAsPresentKeyWithEmptyStringValue(): void
    {
        $content = new SummaryMilestoneContent(
            LocalDate::of(2025, 1, 15),
            '',
            MilestoneCategory::Other,
        );

        $json = $content->jsonSerialize();

        self::assertArrayHasKey('description', $json);
        self::assertSame('', $json['description']);
    }
}
