<?php

namespace App\Services\Grading;

use App\Models\Question;

/**
 * Selects the grading strategy for a question. New question types register a grader here.
 */
class GraderRegistry
{
    /** @param iterable<QuestionGrader> $graders */
    public function __construct(private readonly iterable $graders) {}

    public function for(Question $question): ?QuestionGrader
    {
        foreach ($this->graders as $grader) {
            if ($grader->supports($question)) {
                return $grader;
            }
        }

        return null;
    }

    /** Normalize with the question's grader, or trim when no grader exists. */
    public function normalize(Question $question, ?string $raw): ?string
    {
        $grader = $this->for($question);
        if ($grader) {
            return $grader->normalize($question, $raw);
        }
        $raw = trim((string) $raw);

        return $raw === '' ? null : $raw;
    }
}
