<?php

declare(strict_types=1);

namespace Diary\Tests\Property;

use Diary\Ai\SummaryEntryContent;
use Diary\Ai\SummaryMilestoneContent;
use Diary\Diary\QuestionSet;
use Diary\Milestone\MilestoneCategory;
use Diary\Support\LocalDate;
use Eris\Generator;
use Eris\TestTrait;
use PHPUnit\Framework\TestCase;

/**
 * Property 2: Records preserve their source content exactly, including null
 * and empty fields.
 *
 * For any {@see SummaryEntryContent} - with any mood rating, any sleep
 * quality including null, and any events/thoughts/emotions text including
 * the empty string - and for any {@see SummaryMilestoneContent} - with any
 * date, category, and description text - that record's JSON serialization
 * contains every source field's exact value: a null sleep quality
 * serializes as JSON null, never an absent key; an empty string serializes
 * as "", never an absent key or placeholder text.
 *
 * Requirements: 1.2, 1.3, 1.7, 1.8.
 */
final class SummaryContentPreservationPropertyTest extends TestCase
{
    use TestTrait;

    // Feature: summary-json-payload, Property 2: Records preserve their source content exactly, including null and empty fields
    public function testEntryContentPreservesEverySourceFieldExactly(): void
    {
        $this->limitTo(100)
            ->forAll(
                self::localDate(),
                Generator\choose(QuestionSet::MOOD_MIN, QuestionSet::MOOD_MAX),
                Generator\oneOf(
                    Generator\constant(null),
                    Generator\choose(QuestionSet::SLEEP_MIN, QuestionSet::SLEEP_MAX)
                ),
                self::freeText(),
                self::freeText(),
                self::freeText()
            )
            ->then(function (
                LocalDate $date,
                int $moodRating,
                ?int $sleepQuality,
                string $events,
                string $thoughts,
                string $emotions
            ): void {
                $content = new SummaryEntryContent($date, $moodRating, $sleepQuality, $events, $thoughts, $emotions);

                $serialized = $content->jsonSerialize();

                self::assertSame(
                    ['date', 'mood_rating', 'sleep_quality', 'events', 'thoughts', 'emotions'],
                    array_keys($serialized)
                );
                self::assertSame($date->toIso(), $serialized['date']);
                self::assertSame($moodRating, $serialized['mood_rating']);
                self::assertArrayHasKey('sleep_quality', $serialized, 'sleep_quality must be present even when null');
                self::assertSame($sleepQuality, $serialized['sleep_quality']);
                self::assertArrayHasKey('events', $serialized, 'events must be present even when empty');
                self::assertSame($events, $serialized['events']);
                self::assertArrayHasKey('thoughts', $serialized, 'thoughts must be present even when empty');
                self::assertSame($thoughts, $serialized['thoughts']);
                self::assertArrayHasKey('emotions', $serialized, 'emotions must be present even when empty');
                self::assertSame($emotions, $serialized['emotions']);
            });
    }

    // Feature: summary-json-payload, Property 2: Records preserve their source content exactly, including null and empty fields
    public function testMilestoneContentPreservesEverySourceFieldExactly(): void
    {
        $this->limitTo(100)
            ->forAll(
                self::localDate(),
                self::freeText(),
                Generator\elements(MilestoneCategory::cases())
            )
            ->then(function (LocalDate $date, string $description, MilestoneCategory $category): void {
                $content = new SummaryMilestoneContent($date, $description, $category);

                $serialized = $content->jsonSerialize();

                self::assertSame(['date', 'category', 'description'], array_keys($serialized));
                self::assertSame($date->toIso(), $serialized['date']);
                self::assertSame($category->value, $serialized['category']);
                self::assertArrayHasKey('description', $serialized, 'description must be present even when empty');
                self::assertSame($description, $serialized['description']);
            });
    }

    /**
     * Arbitrary calendar dates, spanning well over a century either side of
     * the epoch.
     */
    private static function localDate(): \Eris\Generator
    {
        return Generator\map(
            static fn (int $epochDay): LocalDate => LocalDate::fromEpochDay($epochDay),
            Generator\choose(-40000, 40000)
        );
    }

    /**
     * Free text for events/thoughts/emotions/description, including the
     * empty string.
     */
    private static function freeText(): \Eris\Generator
    {
        return Generator\string();
    }
}
