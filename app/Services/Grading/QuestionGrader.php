<?php

namespace App\Services\Grading;

use App\Models\Question;

interface QuestionGrader
{
    /** Short identifier stored on grading results, e.g. "multiple_choice@1". */
    public function name(): string;

    public function supports(Question $question): bool;

    /** Normalize a raw response into the canonical form compared against the answer key; null means blank. */
    public function normalize(Question $question, ?string $raw): ?string;

    /** Grade an already-normalized answer. Must be deterministic and free of side effects. */
    public function grade(Question $question, ?string $answer): GradeOutcome;
}
