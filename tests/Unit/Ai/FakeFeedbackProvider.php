<?php

declare(strict_types=1);

namespace Diary\Tests\Unit\Ai;

use Diary\Ai\CbtRecommendation;
use Diary\Ai\EntryContent;
use Diary\Ai\FeedbackProvider;
use Diary\Ai\ProviderError;

/**
 * A test-only {@see FeedbackProvider} that never makes a network call. Queue a
 * recommendation or a {@see ProviderError} with {@see self::queue()} /
 * {@see self::queueFailure()} and inspect every call through {@see self::calls()}.
 */
final class FakeFeedbackProvider implements FeedbackProvider
{
    /** @var list<CbtRecommendation|ProviderError> */
    private array $queue = [];

    /** @var list<EntryContent> */
    private array $calls = [];

    public function queue(CbtRecommendation $recommendation): self
    {
        $this->queue[] = $recommendation;

        return $this;
    }

    public function queueFailure(ProviderError $error): self
    {
        $this->queue[] = $error;

        return $this;
    }

    public function generate(EntryContent $content): CbtRecommendation
    {
        $this->calls[] = $content;

        $next = array_shift($this->queue);

        if ($next === null) {
            throw new ProviderError('FakeFeedbackProvider has no queued response left.');
        }

        if ($next instanceof ProviderError) {
            throw $next;
        }

        return $next;
    }

    public function callCount(): int
    {
        return count($this->calls);
    }
}
