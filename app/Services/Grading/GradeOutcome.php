<?php

namespace App\Services\Grading;

/**
 * Result of grading one answer to one question. Immutable.
 *
 * $details holds automatically scored criterion rows (currently the final answer of a
 * problem-solving question). $rubricPending marks outcomes whose remaining points come
 * from a rubric only a teacher can score, as opposed to answers the grader could not read.
 */
final class GradeOutcome
{
    /**
     * @param  list<array{label: string, max_points: float, score: ?float}>  $details
     */
    public function __construct(
        public readonly ?float $score,
        public readonly ?bool $isCorrect,
        public readonly string $explanation,
        public readonly bool $needsReview = false,
        public readonly ?string $reviewReason = null,
        public readonly array $details = [],
        public readonly bool $rubricPending = false,
    ) {}

    public static function review(string $reason, ?float $proposedScore = 0.0, string $explanation = ''): self
    {
        return new self($proposedScore, null, $explanation !== '' ? $explanation : $reason, true, $reason);
    }

    public function withReview(string $reason, ?bool $rubricPending = null): self
    {
        return new self($this->score, $this->isCorrect, $this->explanation, true, $reason, $this->details, $rubricPending ?? $this->rubricPending);
    }
}
