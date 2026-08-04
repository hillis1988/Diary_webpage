<?php

declare(strict_types=1);

namespace Diary\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The configuration template is a contract: every consumer (PDO factory, KeyRing, AI adapters,
 * cron endpoints) reads these keys. It also has to stay free of real secrets, and the real file
 * has to stay untracked.
 */
final class ConfigurationTemplateTest extends TestCase
{
    private const ROOT = __DIR__ . '/../..';

    /**
     * @return array<string, mixed>
     */
    private function template(): array
    {
        $config = require self::ROOT . '/config/config.example.php';
        self::assertIsArray($config);

        return $config;
    }

    public function testTemplateDocumentsEverySettingTheApplicationNeeds(): void
    {
        $config = $this->template();

        self::assertArrayHasKey('app', $config);
        self::assertArrayHasKey('env', $config['app']);
        self::assertArrayHasKey('base_url', $config['app']);
        self::assertArrayHasKey('force_https', $config['app']);

        foreach (['host', 'port', 'name', 'user', 'password', 'charset'] as $key) {
            self::assertArrayHasKey($key, $config['database'], "database.$key is missing");
        }

        self::assertArrayHasKey('master_key_base64', $config['encryption']);

        foreach (['enabled', 'provider', 'endpoint', 'api_key', 'model', 'timeout_seconds', 'retries'] as $key) {
            self::assertArrayHasKey($key, $config['ai'], "ai.$key is missing");
        }

        self::assertArrayHasKey('token', $config['cron']);
    }

    public function testAiSwitchIsBooleanAndEndpointIsHttps(): void
    {
        $config = $this->template();

        self::assertIsBool($config['ai']['enabled']);
        self::assertStringStartsWith('https://', $config['ai']['endpoint']);
        self::assertStringStartsWith('https://', $config['app']['base_url']);
    }

    public function testTemplateCarriesNoSecretValues(): void
    {
        $config = $this->template();

        self::assertSame('', $config['encryption']['master_key_base64']);
        self::assertSame('', $config['ai']['api_key']);
        self::assertSame('', $config['cron']['token']);
    }

    public function testRealConfigFileIsUntracked(): void
    {
        $gitignore = file_get_contents(self::ROOT . '/.gitignore');

        self::assertIsString($gitignore);
        self::assertStringContainsString('/config/config.php', $gitignore);
    }
}
