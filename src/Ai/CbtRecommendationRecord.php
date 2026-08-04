<?php

declare(strict_types=1);

namespace Diary\Ai;

use DateTimeImmutable;
use LogicException;

/**
 * One `cbt_recommendations` row as read back from storage: the bookkeeping
 * columns plus, only when `status` is `Generated`, the decrypted
 * recommendation.
 *
 * The id is stable across retries (Requirement 6.4: at most one row per
 * entry) - {@see CbtRecommendationRepository} upserts keyed on `entry_id`,
 * so a retry after a failure updates this same row rather than adding a
 * second one.
 */
final class CbtRecommendationRecord
{
    private function __construct(
        private readonly string $id,
        private readonly string $entryId,
        private readonly FeedbackStatus $status,
        private readonly ?CbtRecommendation $recommendation,
        private readonly string $provider,
        private readonly string $model,
        private readonly int $attemptCount,
        private readonly ?DateTimeImmutable $generatedAt,
    ) {
    }

    public static function generated(
        string $id,
        string $entryId,
        CbtRecommendation $recommendation,
        string $provider,
        string $model,
        int $attemptCount,
        DateTimeImmutable $generatedAt,
    ): self {
        return new self($id, $entryId, FeedbackStatus::Generated, $recommendation, $provider, $model, $attemptCount, $generatedAt);
    }

    public static function failed(
        string $id,
        string $entryId,
        string $provider,
        string $model,
        int $attemptCount,
    ): self {
        return new self($id, $entryId, FeedbackStatus::Failed, null, $provider, $model, $attemptCount, null);
    }

    public function id(): string
    {
        return $this->id;
    }

    /** The `Diary_Entry` this recommendation is linked to. */
    public function entryId(): string
    {
        return $this->entryId;
    }

    public function status(): FeedbackStatus
    {
        return $this->status;
    }

    public function isGenerated(): bool
    {
        return $this->status === FeedbackStatus::Generated;
    }

    /**
     * @throws LogicException when the status is `Failed`; check isGenerated() first
     */
    public function recommendation(): CbtRecommendation
    {
        if ($this->recommendation === null) {
            throw new LogicException('A failed recommendation record carries no recommendation; check isGenerated() first.');
        }

        return $this->recommendation;
    }

    /** The processing trail for the AI data-processor record (design.md). */
    public function provider(): string
    {
        return $this->provider;
    }

    public function model(): string
    {
        return $this->model;
    }

    /** How many provider attempts, across every generation and retry, produced this row. */
    public function attemptCount(): int
    {
        return $this->attemptCount;
    }

    /** Null on a failed attempt; the row carries no successful generation to time-stamp. */
    public function generatedAt(): ?DateTimeImmutable
    {
        return $this->generatedAt;
    }
}
