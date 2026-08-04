<?php

declare(strict_types=1);

namespace Diary\Tests\Unit\Ai;

use Diary\Ai\SummaryPromptBuilder;
use PHPUnit\Framework\TestCase;

/**
 * Requirements 3.1-3.5 and 4.1-4.5: {@see SummaryPromptBuilder::systemPrompt()}
 * is a fixed instruction string. Each test below asserts that the exact
 * phrase covering one requirement is present in the returned string.
 */
final class SummaryPromptBuilderTest extends TestCase
{
    public function testSystemPromptOrdersTheTimelineChronologicallyFromEarliestToLatest(): void
    {
        $prompt = (new SummaryPromptBuilder())->systemPrompt();

        // Requirement 3.1
        self::assertStringContainsString('ordered chronologically from earliest to latest date', $prompt);
    }

    public function testSystemPromptRequiresChronologicalReasoningAboutTrendDirection(): void
    {
        $prompt = (new SummaryPromptBuilder())->systemPrompt();

        // Requirement 3.2
        self::assertStringContainsString(
            'Use that chronological order to assess whether the trends across the date range show '
                . 'improvement, decline, or stability',
            $prompt
        );
    }

    public function testSystemPromptRequiresStrictJsonWithNoMarkdownCommentaryOrOtherText(): void
    {
        $prompt = (new SummaryPromptBuilder())->systemPrompt();

        // Requirement 3.3
        self::assertStringContainsString(
            'Respond with strict JSON only - no markdown, no commentary, no surrounding text',
            $prompt
        );
        self::assertStringContainsString('Do not add any other fields.', $prompt);
    }

    public function testSystemPromptForbidsInventingStatisticsBeyondTheGivenTrendMetrics(): void
    {
        $prompt = (new SummaryPromptBuilder())->systemPrompt();

        // Requirement 3.4
        self::assertStringContainsString('Do not invent statistics beyond the trend metrics given in the JSON.', $prompt);
    }

    public function testSystemPromptForbidsRequestingGuessingOrReferencingIdentifyingInformation(): void
    {
        $prompt = (new SummaryPromptBuilder())->systemPrompt();

        // Requirement 3.5
        self::assertStringContainsString(
            'Do not ask for, guess, or reference the person\'s name, email address, or any other '
                . 'identifying information',
            $prompt
        );
    }

    public function testSystemPromptRequiresExactlyTwoTopLevelFieldsSummaryAndAdvice(): void
    {
        $prompt = (new SummaryPromptBuilder())->systemPrompt();

        // Requirement 4.1
        self::assertStringContainsString(
            'containing exactly two top-level fields: a string field "summary", and an object field '
                . '"advice" containing exactly four string fields: "pattern", "distortions", '
                . '"balanced_perspective", and "next_action"',
            $prompt
        );
    }

    public function testSystemPromptRequiresSummaryToBeMultipleSentencesNotASingleTerseSentence(): void
    {
        $prompt = (new SummaryPromptBuilder())->systemPrompt();

        // Requirement 4.2
        self::assertStringContainsString('Write "summary" as multiple sentences', $prompt);
        self::assertStringContainsString('it must not be a single terse sentence', $prompt);
    }

    public function testSystemPromptRequiresPatternBalancedPerspectiveAndNextActionAsShortParagraphs(): void
    {
        $prompt = (new SummaryPromptBuilder())->systemPrompt();

        // Requirement 4.3
        self::assertStringContainsString('Write "pattern" as a short paragraph of multiple sentences', $prompt);
        self::assertStringContainsString('Write "balanced_perspective" as a short paragraph offering', $prompt);
        self::assertStringContainsString('Write "next_action" as a short paragraph describing one concrete action', $prompt);
    }

    public function testSystemPromptRequiresDistortionsToBeNamedOrExplicitlyNoneWithoutFabrication(): void
    {
        $prompt = (new SummaryPromptBuilder())->systemPrompt();

        // Requirement 4.4
        self::assertStringContainsString(
            'Write "distortions" as a short paragraph naming any cognitive distortions you identify '
                . 'across the entries, or explicitly state that none were identified if none apply',
            $prompt
        );
        self::assertStringContainsString('never fabricate one where none fits', $prompt);
    }

    public function testSystemPromptGroundsSummaryAndAdviceInCognitiveBehaviouralTherapy(): void
    {
        $prompt = (new SummaryPromptBuilder())->systemPrompt();

        // Requirement 4.5
        self::assertStringContainsString(
            'Ground "summary" and "advice" in Cognitive Behavioural Therapy: distinguish facts from '
                . 'interpretations',
            $prompt
        );
        self::assertStringContainsString(
            'validate the person\'s emotion without automatically validating the interpretation '
                . 'producing it',
            $prompt
        );
        self::assertStringContainsString('favour credible balanced thinking over forced positivity', $prompt);
        self::assertStringContainsString('do not diagnose any condition', $prompt);
    }
}
