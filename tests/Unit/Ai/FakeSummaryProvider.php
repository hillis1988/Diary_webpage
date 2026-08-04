<?php

declare(strict_types=1);

namespace Diary\Tests\Unit\Ai;

use Diary\Ai\ProgressSummary;
use Diary\Ai\ProviderError;
use Diary\Ai\SummaryInput;
use Diary\Ai\SummaryProvider;

/**
 * A test-only {@see SummaryProvider} that never makes a network call. Queue a
 * summary or a {@see ProviderError} with {@see self::queue()} /
 * {@see self::queueFailure()} and inspect every call through
 * {@see self::calls()}. Mirrors {@see FakeFeedbackProvider}.
 */
final class FakeSummaryProvider implements SummaryProvider
{
    /** @var list<ProgressSummary|ProviderError> */
    private array $queue = [];

    /** @var list<SummaryInput> */
    private array $calls = [];

    public function queue(ProgressSummary $summary): self
    {
        $this->queue[] = $summary;

        return $this;
    }

    public function queueFailure(ProviderError $error): self
    {
        $this->queue[] = $error;

        return $this;
    }

    public function generate(SummaryInput $input): ProgressSummary
    {
        $this->calls[] = $input;

        $next = array_shift($this->queue);

        if ($next === null) {
            throw new ProviderError('FakeSummaryProvider has no queued response left.');
        }

        if ($next instanceof ProviderError) {
            throw $next;
        }

        return $next;
    }

    /**
     * @return list<SummaryInput>
     */
    public function calls(): array
    {
        return $this->calls;
    }

    public function callCount(): int
    {
        return count($this->calls);
    }
}
