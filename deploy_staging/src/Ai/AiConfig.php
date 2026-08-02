<?php

declare(strict_types=1);

namespace Diary\Ai;

/**
 * The `ai` section of the application configuration, read once into a typed
 * shape (Requirement 6.1).
 *
 * `enabled` is the single configuration switch that can disable AI entirely;
 * every AI-calling adapter checks it before making a network call, not after
 * (see `HttpsFeedbackProvider::generate()`), so turning it off truly makes no
 * outbound request rather than merely discarding one.
 */
final class AiConfig
{
    private function __construct(
        private readonly bool $enabled,
        private readonly string $provider,
        private readonly string $endpoint,
        private readonly string $apiKey,
        private readonly string $model,
        private readonly int $timeoutSeconds,
        private readonly int $retries,
    ) {
    }

    /**
     * Build from the application configuration array (or just its 'ai'
     * section).
     *
     * @param array<string, mixed> $config
     */
    public static function fromConfig(array $config): self
    {
        $section = array_key_exists('ai', $config) ? $config['ai'] : $config;
        $section = is_array($section) ? $section : [];

        return new self(
            enabled: (bool) ($section['enabled'] ?? false),
            provider: self::stringOr($section, 'provider', ''),
            endpoint: self::stringOr($section, 'endpoint', ''),
            apiKey: self::stringOr($section, 'api_key', ''),
            model: self::stringOr($section, 'model', ''),
            timeoutSeconds: max(1, (int) ($section['timeout_seconds'] ?? 20)),
            retries: max(0, (int) ($section['retries'] ?? 1)),
        );
    }

    /**
     * The master switch. False disables every AI call outright.
     */
    public function enabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Free-form provider label recorded with each stored recommendation for
     * the processing trail; never sent as part of the request itself.
     */
    public function provider(): string
    {
        return $this->provider;
    }

    public function endpoint(): string
    {
        return $this->endpoint;
    }

    public function apiKey(): string
    {
        return $this->apiKey;
    }

    public function model(): string
    {
        return $this->model;
    }

    /** Hard request timeout in seconds. */
    public function timeoutSeconds(): int
    {
        return $this->timeoutSeconds;
    }

    /** Number of retries after the first failed attempt. */
    public function retries(): int
    {
        return $this->retries;
    }

    /**
     * @param array<string, mixed> $section
     */
    private static function stringOr(array $section, string $key, string $default): string
    {
        $value = $section[$key] ?? null;

        return is_string($value) ? $value : $default;
    }
}
