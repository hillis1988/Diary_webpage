<?php

declare(strict_types=1);

namespace Diary\Tests\Unit\Ai;

use Diary\Ai\PositivesPromptBuilder;
use PHPUnit\Framework\TestCase;

final class PositivesPromptBuilderTest extends TestCase
{
    public function testSystemPromptUsesFriendToneNotTherapistLanguage(): void
    {
        $prompt = (new PositivesPromptBuilder())->systemPrompt();

        self::assertStringContainsString('warm, optimistic friend', $prompt);
        self::assertStringContainsString('NOT a therapist', $prompt);
        self::assertStringContainsString('highlights', $prompt);
        self::assertStringContainsString('why_it_mattered', $prompt);
        self::assertStringContainsString('keep_going', $prompt);
        self::assertStringNotContainsString('Cognitive Behavioural Therapy', $prompt);
    }

    public function testSystemPromptRequestsStrictJson(): void
    {
        $prompt = (new PositivesPromptBuilder())->systemPrompt();

        self::assertStringContainsString('strict JSON', $prompt);
        self::assertStringContainsString('greeting', $prompt);
        self::assertStringContainsString('encouragement', $prompt);
    }
}
