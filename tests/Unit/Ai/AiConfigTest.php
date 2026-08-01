<?php

declare(strict_types=1);

namespace Diary\Tests\Unit\Ai;

use Diary\Ai\AiConfig;
use PHPUnit\Framework\TestCase;

/**
 * AiConfig reads the `ai` section of config/config.php - the single switch
 * that can disable AI entirely, plus the settings the HTTPS adapter needs.
 */
final class AiConfigTest extends TestCase
{
    public function testReadsEveryFieldFromTheAiSection(): void
    {
        $config = AiConfig::fromConfig(['ai' => [
            'enabled' => true,
            'provider' => 'example-provider',
            'endpoint' => 'https://api.example.com/v1/chat/completions',
            'api_key' => 'secret',
            'model' => 'example-model',
            'timeout_seconds' => 20,
            'retries' => 1,
        ]]);

        self::assertTrue($config->enabled());
        self::assertSame('example-provider', $config->provider());
        self::assertSame('https://api.example.com/v1/chat/completions', $config->endpoint());
        self::assertSame('secret', $config->apiKey());
        self::assertSame('example-model', $config->model());
        self::assertSame(20, $config->timeoutSeconds());
        self::assertSame(1, $config->retries());
    }

    public function testDefaultsToDisabledWhenTheAiSectionIsMissing(): void
    {
        $config = AiConfig::fromConfig([]);

        self::assertFalse($config->enabled());
    }

    public function testEnabledFalseIsRespected(): void
    {
        $config = AiConfig::fromConfig(['ai' => ['enabled' => false]]);

        self::assertFalse($config->enabled());
    }
}
