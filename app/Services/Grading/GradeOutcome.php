<?php

namespace App\Services\Grading;

/**
 * Result of grading one answer to one question. Immutable.
 */
final class GradeOutcome
{
    public function __construct(
        public readonly ?float $score,
        public readonly ?bool $isCorrect,
        public readonly string $explanation,
        public readonly bool $needsReview = false,
        public readonly ?string $reviewReason = null,
    ) {}

    public static function review(string $reason, ?float $proposedScore = 0.0, string $explanation = ''): self
    {
        return new self($proposedScore, null, $explanation !== '' ? $explanation : $reason, true, $reason);
    }

    public function withReview(string $reason): self
    {
        return new self($this->score, $this->isCorrect, $this->explanation, true, $reason);
    }
}
