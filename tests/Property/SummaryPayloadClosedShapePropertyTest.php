<?php

declare(strict_types=1);

namespace Diary\Tests\Property;

use Diary\Ai\SeriesStats;
use Diary\Ai\SummaryEntryContent;
use Diary\Ai\SummaryMilestoneContent;
use Diary\Ai\SummaryPayload;
use Diary\Ai\TrendDirection;
use Diary\Ai\TrendMetrics;
use Diary\Milestone\MilestoneCategory;
use Diary\Support\DateRange;
use Diary\Support\LocalDate;
use Eris\Generator;
use Eris\TestTrait;
use JsonException;
use PHPUnit\Framework\TestCase;

/**
 * Property 4: The Summary_Payload never carries a field outside its closed,
 * non-identifying shape.
 *
 * For any SummaryInput, the SummaryPayload's JSON-serialized form - at the
 * top level, and within every entry and milestone record - contains only the
 * fixed key set (date_range, trend_metrics, entries, milestones at the top
 * level; date, mood_rating, sleep_quality, events, thoughts, emotions per
 * entry; date, category, description per milestone), and never contains a
 * key or value that is an owner id, a user id, an email address, or a name.
 *
 * The generator deliberately includes entry/milestone content strings that
 * look like an email address, a UUID, or a person's name, so the test proves
 * the closed-key-set check - not a content-sniffing check - is what makes
 * the property hold: those strings still only ever land inside the existing
 * events/thoughts/emotions/description content values, never as a new key
 * (design.md's Testing Strategy note on Property 4).
 *
 * Requirements: 10.1, 10.2, 10.3.
 */
final class SummaryPayloadClosedShapePropertyTest extends TestCase
{
    use TestTrait;

    private const TOP_LEVEL_KEYS = ['date_range', 'trend_metrics', 'entries', 'milestones'];
    private const ENTRY_KEYS = ['date', 'mood_rating', 'sleep_quality', 'events', 'thoughts', 'emotions'];
    private const MILESTONE_KEYS = ['date', 'category', 'description'];

    // Feature: summary-json-payload, Property 4: The Summary_Payload never carries a field outside its closed, non-identifying shape
    public function testSummaryPayloadNeverCarriesAFieldOutsideItsClosedNonIdentifyingShape(): void
    {
        $this->limitTo(100)
            ->forAll(
                self::dateRange(),
                self::trendMetrics(),
                self::entryList(),
                self::milestoneList()
            )
            ->then(function (
                DateRange $range,
                TrendMetrics $metrics,
                array $entries,
                array $milestones
            ): void {
                $payload = new SummaryPayload($range, $metrics, $entries, $milestones);

                try {
                    $decoded = json_decode(
                        json_encode($payload, JSON_THROW_ON_ERROR),
                        true,
                        512,
                        JSON_THROW_ON_ERROR
                    );
                } catch (JsonException $exception) {
                    self::fail('SummaryPayload failed to JSON-encode: ' . $exception->getMessage());
                }

                self::assertIsArray($decoded);
                self::assertSameKeySet(self::TOP_LEVEL_KEYS, $decoded, 'top level');

                self::assertIsArray($decoded['entries']);
                foreach ($decoded['entries'] as $index => $entryRecord) {
                    self::assertIsArray($entryRecord);
                    self::assertSameKeySet(self::ENTRY_KEYS, $entryRecord, "entries[{$index}]");
                }

                self::assertIsArray($decoded['milestones']);
                foreach ($decoded['milestones'] as $index => $milestoneRecord) {
                    self::assertIsArray($milestoneRecord);
                    self::assertSameKeySet(self::MILESTONE_KEYS, $milestoneRecord, "milestones[{$index}]");
                }
            });
    }

    /**
     * @param list<string> $expectedKeys
     * @param array<string, mixed> $actual
     */
    private static function assertSameKeySet(array $expectedKeys, array $actual, string $label): void
    {
        $actualKeys = array_keys($actual);
        sort($expectedKeys);
        sort($actualKeys);

        self::assertSame($expectedKeys, $actualKeys, "{$label}: key set differs from the fixed shape");
    }

    private static function dateRange(): \Eris\Generator
    {
        return Generator\map(
            static fn (array $dates): DateRange => DateRange::of($dates[0], $dates[1]),
            Generator\tuple(self::localDate(), self::localDate())
        );
    }

    private static function localDate(): \Eris\Generator
    {
        return Generator\map(
            static fn (array $parts): LocalDate => LocalDate::of($parts[0], $parts[1], $parts[2]),
            Generator\tuple(
                Generator\choose(2020, 2030),
                Generator\choose(1, 12),
                Generator\choose(1, 28)
            )
        );
    }

    private static function trendMetrics(): \Eris\Generator
    {
        return Generator\map(
            static fn (array $parts): TrendMetrics => TrendMetrics::of($parts[0], $parts[1], $parts[2]),
            Generator\tuple(
                Generator\choose(0, 30),
                self::seriesStats(),
                self::seriesStats()
            )
        );
    }

    /**
     * Either an empty series (count 0, mean/min/max null) or a non-empty
     * series with arbitrary count/mean/min/max/direction.
     */
    private static function seriesStats(): \Eris\Generator
    {
        return Generator\oneOf(
            Generator\constant(SeriesStats::of(0, null, null, null, TrendDirection::Stable)),
            Generator\map(
                static function (array $parts): SeriesStats {
                    [$count, $mean, $min, $max, $direction] = $parts;
                    $lower = min($min, $max);
                    $upper = max($min, $max);

                    return SeriesStats::of($count, $mean, $lower, $upper, $direction);
                },
                Generator\tuple(
                    Generator\choose(1, 30),
                    Generator\map(static fn (int $value): float => $value / 10, Generator\choose(-100, 100)),
                    Generator\choose(1, 10),
                    Generator\choose(1, 10),
                    Generator\elements(TrendDirection::cases())
                )
            )
        );
    }

    private static function entryList(): \Eris\Generator
    {
        return Generator\bind(
            Generator\choose(0, 5),
            static fn (int $length): \Eris\Generator => $length === 0
                ? Generator\constant([])
                : Generator\vector($length, self::entryContent())
        );
    }

    private static function entryContent(): \Eris\Generator
    {
        return Generator\map(
            static fn (array $parts): SummaryEntryContent => new SummaryEntryContent(
                $parts[0],
                $parts[1],
                $parts[2],
                $parts[3],
                $parts[4],
                $parts[5]
            ),
            Generator\tuple(
                self::localDate(),
                Generator\choose(1, 10),
                Generator\oneOf(Generator\constant(null), Generator\choose(1, 5)),
                self::identityLookingText(),
                self::identityLookingText(),
                self::identityLookingText()
            )
        );
    }

    private static function milestoneList(): \Eris\Generator
    {
        return Generator\bind(
            Generator\choose(0, 5),
            static fn (int $length): \Eris\Generator => $length === 0
                ? Generator\constant([])
                : Generator\vector($length, self::milestoneContent())
        );
    }

    private static function milestoneContent(): \Eris\Generator
    {
        return Generator\map(
            static fn (array $parts): SummaryMilestoneContent => new SummaryMilestoneContent(
                $parts[0],
                $parts[1],
                $parts[2]
            ),
            Generator\tuple(
                self::localDate(),
                self::identityLookingText(),
                Generator\elements(MilestoneCategory::cases())
            )
        );
    }

    /**
     * Arbitrary text: the empty string, ordinary free text, or one of
     * several strings that look exactly like an email address, a UUID, or a
     * person's full name - the kind of value that, if it ever leaked out as
     * a *key* rather than sitting inside an existing content value, would be
     * an identifying-field regression. Confirming the closed key set holds
     * even when content looks like this is the point of Property 4.
     */
    private static function identityLookingText(): \Eris\Generator
    {
        return Generator\oneOf(
            Generator\constant(''),
            self::plainFreeText(),
            self::emailLookingText(),
            self::uuidLookingText(),
            self::nameLookingText()
        );
    }

    private static function plainFreeText(): \Eris\Generator
    {
        return Generator\map(
            static fn (array $characters): string => implode('', $characters),
            Generator\vector(
                10,
                Generator\elements(str_split('abcdefghijklmnopqrstuvwxyz ., '))
            )
        );
    }

    private static function emailLookingText(): \Eris\Generator
    {
        return Generator\map(
            static fn (array $parts): string => "{$parts[0]}@{$parts[1]}.com",
            Generator\tuple(
                self::wordOf('abcdefghijklmnopqrstuvwxyz.'),
                self::wordOf('abcdefghijklmnopqrstuvwxyz')
            )
        );
    }

    private static function uuidLookingText(): \Eris\Generator
    {
        return Generator\map(
            static fn (array $groups): string => sprintf(
                '%s-%s-%s-%s-%s',
                $groups[0],
                $groups[1],
                $groups[2],
                $groups[3],
                $groups[4]
            ),
            Generator\tuple(
                self::hexOf(8),
                self::hexOf(4),
                self::hexOf(4),
                self::hexOf(4),
                self::hexOf(12)
            )
        );
    }

    private static function nameLookingText(): \Eris\Generator
    {
        return Generator\map(
            static fn (array $parts): string => "{$parts[0]} {$parts[1]}",
            Generator\tuple(
                self::capitalizedWord(),
                self::capitalizedWord()
            )
        );
    }

    private static function capitalizedWord(): \Eris\Generator
    {
        return Generator\map(
            static fn (string $word): string => ucfirst($word),
            self::wordOf('abcdefghijklmnopqrstuvwxyz')
        );
    }

    private static function wordOf(string $alphabet): \Eris\Generator
    {
        return Generator\map(
            static fn (array $characters): string => implode('', $characters),
            Generator\vector(6, Generator\elements(str_split($alphabet)))
        );
    }

    private static function hexOf(int $length): \Eris\Generator
    {
        return Generator\map(
            static fn (array $characters): string => implode('', $characters),
            Generator\vector($length, Generator\elements(str_split('0123456789abcdef')))
        );
    }
}
