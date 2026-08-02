<?php

declare(strict_types=1);

namespace Diary\Tests\Unit\Ai;

use Diary\Ai\EntryContent;
use Diary\Ai\PromptBuilder;
use PHPUnit\Framework\TestCase;

/**
 * Requirement 6.1: prompts sent to the AI provider are pseudonymised - entry
 * content only, never an account identifier, email address or name.
 *
 * This test asserts that structurally: {@see EntryContent} itself carries no
 * such field, so there is nothing for the prompt to include even by mistake.
 * A more thorough snapshot-style assertion covering the full prompt text
 * belongs to task 10.3.
 */
final class PromptBuilderTest extends TestCase
{
    private const IDENTIFIER_NEEDLES = [
        'roy',
        'hillis',
        '@',
        'royhillis.co.uk',
        'user_id',
        'userid',
        'account',
        'email',
    ];

    private function content(array $overrides = []): EntryContent
    {
        $defaults = [
            'moodRating' => 7,
            'sleepQuality' => 3,
            'events' => 'Went for a walk in the park.',
            'thoughts' => 'Felt calmer than yesterday.',
            'emotions' => 'Content, a little tired.',
        ];
        $values = [...$defaults, ...$overrides];

        return new EntryContent(
            $values['moodRating'],
            $values['sleepQuality'],
            $values['events'],
            $values['thoughts'],
            $values['emotions'],
        );
    }

    public function testUserPromptIncludesTheEntryContent(): void
    {
        $prompt = (new PromptBuilder())->userPrompt($this->content());

        self::assertStringContainsString('7', $prompt);
        self::assertStringContainsString('Went for a walk in the park.', $prompt);
        self::assertStringContainsString('Felt calmer than yesterday.', $prompt);
        self::assertStringContainsString('Content, a little tired.', $prompt);
    }

    public function testUserPromptOmitsSleepQualityWhenAbsent(): void
    {
        $prompt = (new PromptBuilder())->userPrompt($this->content(['sleepQuality' => null]));

        self::assertStringNotContainsString('sleep', mb_strtolower($prompt)) ;
    }

    /**
     * Requirement 6.1: never an account identifier, email address or name.
     * EntryContent has no such field, so this asserts the negative directly
     * against generated prompt text using content that would surface a leak
     * if one existed.
     */
    public function testUserPromptNeverContainsIdentifyingInformation(): void
    {
        $prompt = mb_strtolower((new PromptBuilder())->userPrompt($this->content()));

        foreach (self::IDENTIFIER_NEEDLES as $needle) {
            self::assertStringNotContainsString($needle, $prompt, sprintf(
                'The prompt must never contain the identifying fragment "%s".',
                $needle
            ));
        }
    }

    public function testSystemPromptRequestsStrictJsonWithTheExpectedFields(): void
    {
        $prompt = (new PromptBuilder())->systemPrompt();

        self::assertStringContainsString('JSON', $prompt);
        self::assertStringContainsString('positive_focus', $prompt);
        self::assertStringContainsString('suggested_change', $prompt);
    }

    /**
     * Requirement 6.1: a full-text snapshot of both prompts for a fixed
     * {@see EntryContent}. Unlike the needle-based assertions above, this
     * pins the exact wording so any future change that widens the prompt
     * beyond the entry's own content and derived metrics - a stray label, an
     * extra field, an interpolated identifier - shows up as a literal diff
     * here rather than silently passing because it happens not to match one
     * of the negative needles.
     */
    public function testPromptsMatchTheExactExpectedTextForAFixedEntry(): void
    {
        $content = new EntryContent(
            7,
            3,
            'Went for a walk in the park.',
            'Felt calmer than yesterday.',
            'Content, a little tired.',
        );
        $builder = new PromptBuilder();

        $expectedSystemPrompt = 'You are a CBT-informed wellbeing assistant giving a single, one-shot review of one '
            . 'day\'s diary entry. Ground your response in Cognitive Behavioural Therapy: distinguish '
            . 'facts from interpretations, validate the person\'s emotion without automatically '
            . 'validating the interpretation producing it, and favour credible, evidence-based '
            . 'balanced thinking over forced positivity or empty reassurance. Not every difficulty is '
            . 'a thinking error - some things are genuinely hard - so do not force a cognitive '
            . 'reframe where none fits. You are not a doctor, therapist, or emergency service: do not '
            . 'diagnose conditions, reference medication, or claim certainty about the person\'s '
            . 'situation. Respond with strict JSON only - no markdown, no commentary, no surrounding '
            . 'text - containing exactly two string fields: "positive_focus" (one positive thing, '
            . 'grounded in the entry, the person can focus on today) and "suggested_change" (one '
            . 'small, credible, CBT-informed change they could try). Do not add any other fields, ask '
            . 'a question, or invite further conversation - this is a single response, not the start '
            . 'of a dialogue. Do not ask for, guess, or reference the person\'s name, email address, '
            . 'or any other identifying information; you are only ever given the content of a single '
            . 'entry.';

        $expectedUserPrompt = implode("\n", [
            'How would you rate your mood today?: 7 out of 10 (scale: 1 (very low) to 10 (very good))',
            'How well did you sleep last night?: 3 out of 5 (scale: 1 (very poor) to 5 (very good))',
            'What happened today that felt notable? Went for a walk in the park.',
            'What thoughts have been going through your mind? Felt calmer than yesterday.',
            'Which emotions did you notice, and how strong were they? Content, a little tired.',
        ]);

        self::assertSame($expectedSystemPrompt, $builder->systemPrompt());
        self::assertSame($expectedUserPrompt, $builder->userPrompt($content));
    }
}
